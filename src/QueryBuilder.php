<?php namespace Hbclare\ModelHelper;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Cache;

class QueryBuilder extends Builder
{
    /**
     * @var Model
     */
    private $model;

    private $_readKey;
    private $_flushKey;


    //设置Model对象
    public function setModel(Model $model)
    {
        $this->model = $model;
    }

    //读写缓存判断
    private function needCache()
    {
        //总开关
        if (!$this->model || !$this->model->needCache()) {
            return false;
        }
        //处理缓存key
        $setCacheKey = $this->model->getCacheKeys();
        $autoCache = $this->model->getAutoEachCache();
        if(empty($setCacheKey)    #未设置写入key
            && true === $autoCache #且自动原子化缓存开启
            && $this->isSimple() #且是简单的sql
        ){
            $setCacheKey = 'autoCache_'.$this->model->table().'_'
                .$this->model->primaryKey().'_{'.$this->model->primaryKey().'}';
        }

        if(empty($setCacheKey)){
            return false;
        }

        $this->_readKey = $this->replaceKey($setCacheKey);
        return true;
    }

    //清理缓存判断
    /**
     * 清理缓存判断
     * @param string $type i insert, u update, d delete
     * @return bool
     */
    private function needFlushCache($type='u')
    {
        //总开关
        if (!$this->model || !$this->model->needCache()) {
            return false;
        }
        //获取设置的清除key
        $flushKeyArr = $this->model->getFlushKeys();

        //获取自动清除类
        if('i' == $type){
            $autoFlush = $this->model->getAfterInsertFlushKey();
        }else if('u' == $type){
            $autoFlush = $this->model->getAfterUpdateFlushKey();
        }else if('d' == $type){
            $autoFlush = $this->model->getAfterDeleteFlushKey();
        }
        $autoEachCache = [];
        $realAutoFlushKey = $this->formatAutoKey($autoFlush);
        if( true === $this->model->getAutoEachCache() && 'i' !== $type){#透明处理
            $autoEachCache[] = 'autoCache_'.$this->model->table().'_'
                .$this->model->primaryKey().'_{'.$this->model->primaryKey().'}';
        }
        $flushKey = array_merge($flushKeyArr, $realAutoFlushKey, $autoEachCache);
        if( 0 == count($autoEachCache) ){
            return false;
        }
        $this->_flushKey =$this->formatAutoKey($flushKey);
        return true;
    }

    /**
     * 判断当前查询是否未「复杂查询」，判断标准
     * 1. 含有 max, sum 等汇聚函数
     * 2. 包含 distinct 指令
     * 3. 包含分组
     * 4. 包含连表
     * 5. 包含联合
     * 6. 包含子查询
     * 7. 包含原生（raw）语句
     * 8. 包含排序
     *
     * 复杂查询使用表级缓存，命中率较低
     */
    private function isAwful()
    {
        if (self::hasRawColumn($this->columns)) {
            return true;
        }

        return $this->aggregate
        or $this->distinct
        or $this->groups
        or $this->joins
        or $this->orders
        or $this->unions
        or !$this->wheres
        or array_key_exists('Exists', $this->wheres)
        or array_key_exists('InSub', $this->wheres)
        or array_key_exists('NotExists', $this->wheres)
        or array_key_exists('NotInSub', $this->wheres)
        or array_key_exists('Sub', $this->wheres)
        or array_key_exists('raw', $this->wheres);
    }
    /**
     * 「简单查询」就是只根据主键过滤结果集的查询，有以下两种形式：
     * 1. select * from foo where id = 1;
     * 2. select * from foo where id in (1, 2, 3);
     */
    private function isSimple()
    {
        if ($this->isAwful()) {
            return false;
        }

        if (!$this->wheres) {
            return false;
        }

        if (count($this->wheres) > 1) {
            return false;
        }

        $where = current($this->wheres);

        if ($where['type'] === 'Nested') {
            return false;
        }

        $id = $this->model->primaryKey();
        $tableId = $this->model->table() . '.' . $this->model->primaryKey();
        if (!in_array($where['column'], [$id, $tableId])) {
            return false;
        }

        if ($where['type'] === 'In') {
            return true;
        }

        if ($where['type'] === 'Basic') {
            if ($where['operator'] === '=') {
                return true;
            }
        }

        return false;
    }

    private static function hasRawColumn($columns)
    {
        if (empty($columns)) {
            return false;
        }

        foreach ($columns as $column) {
            if ($column instanceof Expression) {
                return true;
            }
        }

        return false;
    }

    //重写get动作，让get动作带上cache
    public function get($columns = ['*'])
    {
        if (!$this->needCache())
        {
            //如果不用缓存，直接触发执行
            return parent::get($columns);
        }else{
            //否则，带缓存执行
            return $this->getCacheQuery($columns);
        }

    }


    //使用带缓存的方式，执行sql操作
    private function getCacheQuery($columns){
        #如果缓存不存在
        if(!Cache::has($this->_readKey)){
            $Row = parent::get($columns);
            Cache::put($this->_readKey, $Row, Cache::getDefaultCacheTime());
            return $Row;
        }else{
            return Cache::get($this->_readKey);
        }
    }

    //重写insert动作，让insert带上清理缓存的连带动作
    public function insert(array $values)
    {
        $Row = parent::insert($values);
        if ($this->needFlushCache('i')) {
            $this->cleanKeys();
        }

        return $Row;
    }

    //重写update动作，让update带上清理缓存的连带动作
    public function update(array $values)
    {
        $Row = parent::update($values);
        if ($this->needFlushCache('u')) {
            $this->cleanKeys();
        }
        return $Row;
    }


    //重写delete动作，让delete带上清理缓存的连带动作
    public function delete($id = null)
    {
        $Row = parent::delete($id);
        if ($this->needFlushCache('d')) {
            $this->cleanKeys();
        }

        return $Row;
    }


    /**
     * 格式化自动执行Key
     * @param $cacheKeys Array 缓存处理
     */
    private function formatAutoKey($cacheKeyArr){
        $realKeyArr = array();
        foreach($cacheKeyArr as $key => $value){
            $realKey = $this->replaceKey($value);
            !empty($realKey) && $realKeyArr[] = $realKey;
        }
        return $realKeyArr;
    }

    /**
     * 替换key中的通配符，生成完整的
     * @param $cacheKey
     * @return false or 字段数组
     */
    private function replaceKey($cacheKey){
        $result = array();
        //匹配括号里面的值
        preg_match_all("/(?:\{)(.*)(?:\})/iU", $cacheKey, $result);
        //没有{}直接返回
        if(empty($result[0])){
            return $cacheKey;
        }
        $searchArr = $repaceArr = array();//初始化
        //字段对比
        foreach($result[1] as $key => $value){
            $findValue = $this->findWhereValue($value);
            //只要遇到未查到的情况，抛弃此条
            if(empty($findValue)){
                return false;
            }else{
                $searchArr[] = $result[0][$key];
                $repaceArr[] = $findValue;
            }
        }

        $realKey = str_replace($searchArr, $repaceArr, $cacheKey);
        return $realKey;
    }

    /**
     * 查询值匹配
     * @param $value
     * @return bool
     */
    public function findWhereValue($value){
        //获取字段
        $originWheres = $this->wheres;
        foreach($originWheres as $whereKey => $whereValue){
            if( '=' == $whereValue['operator'] && $value == $whereValue['column']){
                return $whereValue['value'];
            }
        }
        return false;

    }

 /**
     * 清理缓存方法
     */
    private function cleanKeys(){
        foreach($this->_flushKey as $key => $value){
            #如果是redis，key通配符*， ?
            if('redis' == Cache::getDefaultDriver()
                && ( false !== stripos($value, '*')
                    || false !== stripos($value, '?')
                )
            ){
                $redisCacheKey = Cache::getPrefix() . $value;
                $keyArr = Cache::getRedis()->keys($redisCacheKey);
                foreach((array)$keyArr as $k=>$v){
                    $forgetKey = str_replace(Cache::getPrefix(), '', $v);
                    Cache::forget($forgetKey);
                }
            }
            Cache::forget($value);
        }
        $this->_flushKey = array();
    }

}
