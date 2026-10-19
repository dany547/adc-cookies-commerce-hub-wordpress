<?php
/** Prepared SQL repository for operational analytics. */
declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

final class ADC_Analytics_Store
{
    private const COMMERCE_METRICS = ['orders_created','orders_paid','order_total','tax_total','shipping_total','shipping_tax','discount_total','discount_tax','refund_total','items_net','items_tax','units','status_reached'];
    private const PRODUCT_METRICS = ['units','items_net','items_tax','refund_units','refund_net','refund_tax'];
    private const CHANNELS = ['direct','unknown','search','social','referral','internal'];
    private const PAGES = ['home','blog','post','page','product','product_cat','product_tag','category','tag','shop','archive','other'];
    private static int $transactionDepth = 0;
    private static bool $rollbackOnly = false;
    /** @var array<int,callable> after-commit callbacks queued by nested transactions. */
    private static array $afterCommit = array();

    public static function table(string $key): string { return ADC_Analytics_Schema::table($key); }
    /** Run a transaction; callback returning false or throwing rolls back. Nested calls never commit the outer transaction.
     * The optional $after_commit callback runs only after a real COMMIT (used to release day locks post-commit). */
    public static function transaction(callable $callback, ?callable $after_commit = null): bool
    {
        global $wpdb;
        if ($after_commit !== null) { self::$afterCommit[] = $after_commit; }
        if (self::$transactionDepth > 0) {
            self::$transactionDepth++;
            try { $result = $callback(); if ($result === false) { self::$rollbackOnly = true; return false; } return true; }
            catch (Throwable $error) { self::$rollbackOnly = true; throw $error; }
            finally { self::$transactionDepth--; }
        }
        if ($wpdb->query('START TRANSACTION') === false) { return false; }
        self::$transactionDepth = 1;
        self::$rollbackOnly = false;
        try {
            $result = $callback();
            if ($result === false || self::$rollbackOnly) { $wpdb->query('ROLLBACK'); return false; }
            if ($wpdb->query('COMMIT') === false) { return false; }
            // After-commit hooks run strictly after COMMIT, never after a rollback.
            $hooks = self::$afterCommit; self::$afterCommit = array();
            foreach ($hooks as $hook) { $hook(); }
            return true;
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); return false; }
        finally { self::$transactionDepth = 0; self::$rollbackOnly = false; self::$afterCommit = array(); }
    }
    /** Serialize one stat_date against concurrent writers (jobs vs reconciler). MySQL named lock; 0 = refused. */
    public static function lock_day(string $stat_date, int $timeout = 2): bool
    {
        global $wpdb;
        if (!self::validDate($stat_date)) { return false; }
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $wpdb->prefix . 'adc_analytics_day_' . $stat_date, max(0, $timeout)));
        return $result === '1';
    }
    /** Release the per-day lock; always safe to call (idempotent, best effort). */
    public static function unlock_day(string $stat_date): void
    {
        global $wpdb;
        if (!self::validDate($stat_date)) { return; }
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $wpdb->prefix . 'adc_analytics_day_' . $stat_date));
    }
    private static function validDate(string $date): bool { $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date); return $d !== false && $d->format('Y-m-d') === $date; }
    private static function validCurrency(string $currency): bool { return (bool) preg_match('/^[A-Z]{3}$/D', $currency); }
    private static function bump(string $key, string $columns, array $values, string $updates): bool
    {
        global $wpdb;
        if (!isset($values[0]) || !self::validDate((string) $values[0])) { return false; }
        $table = self::table($key);
        $sql = "INSERT INTO %i ($columns) VALUES (" . implode(',', array_fill(0, count($values), '%s')) . ") ON DUPLICATE KEY UPDATE $updates";
        $prepared = $wpdb->prepare($sql, $table, ...array_map(static fn($v) => (string) $v, $values));
        return $wpdb->query($prepared) !== false;
    }
    public static function increment_traffic(string $stat_date, int $hour, string $channel, string $page_type, int $object_id, int $views = 1): bool
    {
        if (!self::validDate($stat_date) || $hour < 0 || $hour > 23 || !in_array($channel,self::CHANNELS,true) || !in_array($page_type,self::PAGES,true) || $object_id < 0 || $views < 1 || $views > 1000000) { return false; }
        return self::bump(ADC_Analytics_Schema::TRAFFIC,'stat_date,stat_hour,channel,page_type,object_id,views,updated_at',[$stat_date,$hour,$channel,$page_type,$object_id,$views,gmdate('Y-m-d H:i:s')],'views = views + VALUES(views), updated_at = VALUES(updated_at)');
    }
    public static function increment_interaction(string $stat_date, string $event_name, int $count = 1): bool
    {
        if (!self::validDate($stat_date) || !in_array($event_name,['add_to_cart','begin_checkout'],true) || $count < 1 || $count > 1000000) { return false; }
        return self::bump(ADC_Analytics_Schema::INTERACTIONS,'stat_date,event_name,count,updated_at',[$stat_date,$event_name,$count,gmdate('Y-m-d H:i:s')],'count = count + VALUES(count), updated_at = VALUES(updated_at)');
    }
    public static function increment_campaign(string $stat_date, string $source, string $medium, string $campaign, int $views = 1): bool { return false; }
    public static function apply_commerce_metric(string $stat_date, string $currency, string $order_status, string $metric, float $amount = 0.0, int $count = 0): bool
    {
        if (!self::validDate($stat_date) || !self::validCurrency($currency) || !in_array($metric,self::COMMERCE_METRICS,true) || !preg_match('/^[a-z0-9_-]{0,20}$/D',$order_status) || !is_finite($amount) || abs($amount) > 999999999999 || $count < 0 || $count > 1000000) { return false; }
        return self::bump(ADC_Analytics_Schema::COMMERCE,'stat_date,currency,order_status,metric,amount,count,updated_at',[$stat_date,$currency,$order_status,$metric,$amount,$count,gmdate('Y-m-d H:i:s')],'amount = amount + VALUES(amount), count = count + VALUES(count), updated_at = VALUES(updated_at)');
    }
    public static function apply_product_metric(string $stat_date, string $currency, int $product_id, array $metrics): bool
    {
        if (!self::validDate($stat_date) || !self::validCurrency($currency) || $product_id < 1 || count($metrics) > 6 || array_diff(array_keys($metrics),self::PRODUCT_METRICS)) { return false; }
        $fields = []; $values = [$stat_date,$currency,$product_id];
        foreach (self::PRODUCT_METRICS as $key) { $value = $metrics[$key] ?? 0; if (!is_numeric($value) || !is_finite((float)$value) || abs((float)$value)>999999999999 || (str_ends_with($key,'units') && ($value < 0 || (int)$value != $value || $value>1000000))) { return false; } $fields[]=$key; $values[]=$value; }
        $values[]=gmdate('Y-m-d H:i:s');
        $updates=implode(', ',array_map(static fn($f)=>"$f = $f + VALUES($f)",$fields)).', updated_at = VALUES(updated_at)';
        return self::bump(ADC_Analytics_Schema::PRODUCTS,'stat_date,currency,product_id,units,items_net,items_tax,refund_units,refund_net,refund_tax,updated_at',$values,$updates);
    }
    public static function claim_ledger(int $order_id, string $event_key, string $status, string $currency, float $amount, string $stat_date, string $occurred_at): bool
    {
        global $wpdb;
        if ($order_id<1 || !preg_match('/^(order_created|order_paid|status_reached:[a-z0-9_-]{1,20}|refund:[1-9][0-9]*)$/D',$event_key) || !preg_match('/^[a-z0-9_-]{0,20}$/D',$status) || ($currency!==''&&!self::validCurrency($currency)) || !is_finite($amount) || abs($amount)>999999999999 || !self::validDate($stat_date) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$occurred_at)) { return false; }
        $table=self::table(ADC_Analytics_Schema::LEDGER); $sql=$wpdb->prepare('INSERT INTO %i (order_id,event_key,status,currency,amount,stat_date,occurred_at,created_at) VALUES (%d,%s,%s,%s,%f,%s,%s,%s) ON DUPLICATE KEY UPDATE order_id=order_id',$table,$order_id,$event_key,$status,$currency,$amount,$stat_date,$occurred_at,gmdate('Y-m-d H:i:s'));
        return $wpdb->query($sql)===1;
    }
    public static function ledger_exists(int $order_id, string $event_key): bool { global $wpdb; if($order_id<1)return false; return (bool)$wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE order_id=%d AND event_key=%s LIMIT 1',self::table(ADC_Analytics_Schema::LEDGER),$order_id,$event_key)); }
    public static function delete_day(string $stat_date): void { if(!self::validDate($stat_date))return; global $wpdb; foreach([ADC_Analytics_Schema::COMMERCE,ADC_Analytics_Schema::PRODUCTS] as $key)$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE stat_date=%s',self::table($key),$stat_date)); }
    private static function rows(string $key,string $from,string $to,array $where=[]): array
    {
        global $wpdb; if(!self::validDate($from)||!self::validDate($to)||$from>$to)return [];
        $table=self::table($key); $sql=$wpdb->prepare('SELECT * FROM %i WHERE stat_date BETWEEN %s AND %s',$table,$from,$to);
        foreach($where as [$column,$value]) { if(!in_array($column,['currency','order_status','page_type','object_id'],true))return []; $sql.=$wpdb->prepare(" AND $column = %s",(string)$value); }
        return (array)$wpdb->get_results($sql,ARRAY_A);
    }
    public static function traffic_rows(string $from,string $to,array $filters=[]): array { if(count($filters)>4)return []; $where=[]; foreach($filters as $k=>$v){if(!in_array($k,['page_type','object_id'],true))return []; $where[]=[$k,$v];} return self::rows(ADC_Analytics_Schema::TRAFFIC,$from,$to,$where); }
    public static function commerce_rows(string $from,string $to,string $currency,array $statuses=[]): array { if(!self::validCurrency($currency)||count($statuses)>20)return []; $rows=self::rows(ADC_Analytics_Schema::COMMERCE,$from,$to,[['currency',$currency]]); return $statuses?array_values(array_filter($rows,static fn($r)=>in_array($r['order_status'],$statuses,true))):$rows; }
    /** All commerce rows for one exact date, any currency/status (reconciler reads and preserves event facts). */
    public static function day_rows(string $stat_date): array
    {
        global $wpdb;
        if (!self::validDate($stat_date)) { return array(); }
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE stat_date = %s', self::table(ADC_Analytics_Schema::COMMERCE), $stat_date), ARRAY_A);
    }
    public static function product_rows(string $from,string $to,string $currency,int $limit=20): array { if(!self::validCurrency($currency)||$limit<1||$limit>100)return []; return self::rows(ADC_Analytics_Schema::PRODUCTS,$from,$to,[['currency',$currency]]); }
    public static function interaction_rows(string $from,string $to): array { return self::rows(ADC_Analytics_Schema::INTERACTIONS,$from,$to); }
    public static function campaign_rows(string $from,string $to,int $limit=20): array { return []; }
    public static function purge(string $table_key,string $before_date): int
    {
        if(!self::validDate($before_date))return 0;
        $allowed=[ADC_Analytics_Schema::TRAFFIC,ADC_Analytics_Schema::INTERACTIONS,ADC_Analytics_Schema::COMMERCE,ADC_Analytics_Schema::PRODUCTS,ADC_Analytics_Schema::CAMPAIGNS];
        if($table_key==='ledger') { $commerce=(int)ADC_Settings::get('analytics','retention_commerce_days',365); $ledger=(int)ADC_Settings::get('analytics','retention_ledger_days',$commerce); if($ledger<$commerce)return 0; $table_key=ADC_Analytics_Schema::LEDGER; $column='stat_date'; }
        else { if(!in_array($table_key,$allowed,true))return 0; $column='stat_date'; }
        global $wpdb; return (int)$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE '.$column.' < %s',self::table($table_key),$before_date));
    }
}
