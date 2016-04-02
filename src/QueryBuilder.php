<?php namespace Angejia\Pea;

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
        $realFlushKey = $this->formatAutoKey($flushKeyArr);

        //获取自动清除类
        if('i' == $type){
            $autoFlush = $this->model->getAfterInsertFlushKey();
        }else if('u' == $type){
            $autoFlush = $this->model->getAfterUpdateFlushKey();
        }else if('d' == $type){
            $autoFlush = $this->model->getAfterDeleteFlushKey();
        }

        $realAutoFlushKey = $this->formatAutoKey($autoFlush);
        $this->_flushKey = $realFlushKey + $realAutoFlushKey;

        if( 0 == count($this->_flushKey) ){
            return false;
        }

        return true;
    }


    /**
     * @return Cache
     */
//    protected function getCache()
//    {
//        return Container::getInstance()->make(Cache::class);
//    }

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
            Cache::put($this->_readKey, $Row, 3600);
            return $Row;
        }else{
            return Cache::get($this->_readKey);
        }
    }

    //重写insert动作，让insert带上清理缓存的连带动作
    public function insert(array $values)
    {
        if ($this->needFlushCache('i')) {
            $this->cleanKeys();
        }

        return parent::insert($values);
    }

    //重写update动作，让update带上清理缓存的连带动作
    public function update(array $values)
    {
        if ($this->needFlushCache('u')) {
            $this->cleanKeys();
        }
        return parent::update($values);
    }


    //重写delete动作，让delete带上清理缓存的连带动作
    public function delete($id = null)
    {
        if ($this->needFlushCache('d')) {
            $this->cleanKeys();
        }

        return parent::delete($id);
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
            Cache::forget($value);
        }
        $this->_flushKey = array();
    }

}
