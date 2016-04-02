<?php namespace Angejia\Pea;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;

abstract class Model extends EloquentModel
{
    //是否开启Model缓存总开关，false关闭，ture开启
    protected $endisabledCache = true;

    //是否开启默认原子化缓存（透明缓存）
    //TODO 还未开发
    protected $autoEachCache = false;

    //需要缓存的key，在一次get()流程中生效
    protected $cacheKey = '';
    //需要清理的缓存名字
    protected $flushKey = array();


    //开启自动原子化缓存
    protected function startAutoEachCache(){
        exit('功能未开放');
        $this->autoEachCache = true;
    }
    //关闭自动原子化缓存
    protected function stopAutoEachCache(){
        $this->autoEachCache = false;
    }


    /*
     * insert以后，需要自动执行清理缓存，支持数组
     * key 支持通配符处理，
     * 如 user_info_{id}
     * 这里id，对应的就是表中的字段，如果 where 条件中有 id=12这样的条件，就会将key变成 user_info_12
     * 同时应该支持多标签模式:user_info_type_{type}_id_{id}
     * 注意，如果通配符字段id=2（一定是等于），不在where条件中，这条缓存key会抛弃
     */
    protected $afterInsertFlushKey = array();
    //update以后，自动执行清理缓存key数组
    protected $afterUpdateFlushKey = array();
    //delete以后，自动执行清理缓存key数组
    protected $afterDeleteFlushKey = array();

    //设置/读取 需设置缓存key
    public function setCacheKeys($key){
        $this->cacheKey = $key;
    }
    public function getCacheKeys(){
        return $this->cacheKey;
    }

    //设置/读取 需删除缓存key
    public function setFlushKeys($key){
        !is_array($key) ?
            $keyArr[] = $key :
            $keyArr = $key;
        $this->flushKey = $keyArr;
    }
    public function getFlushKeys(){
        return $this->flushKey;
    }

    //设置/读取 插入操作后删除缓存key
    public function setAfterInsertFlushKey($key){
        !is_array($key) ?
            $keyArr[] = $key :
            $keyArr = $key;
        $this->afterInsertFlushKey = $keyArr;
    }
    public function getAfterInsertFlushKey(){
        return $this->afterInsertFlushKey;
    }

    //设置/读取 更新操作后删除缓存key
    public function setAfterUpdateFlushKey($key){
        !is_array($key) ?
            $keyArr[] = $key :
            $keyArr = $key;
        $this->afterUpdateFlushKey = $keyArr;
    }
    public function getAfterUpdateFlushKey(){
        return $this->afterUpdateFlushKey;
    }


    //设置/读取 删除操作后删除缓存key
    public function setAfterDeleteFlushKey($key){
        !is_array($key) ?
            $keyArr[] = $key :
            $keyArr = $key;
        $this->afterDeleteFlushKey = $keyArr;
    }
    public function getAfterDeleteFlushKey(){
        return $this->afterDeleteFlushKey;
    }

    /*
     * 判断是否读库时，是否需要写缓存，总开关
     */
    public function needCache()
    {
        return $this->endisabledCache;
    }

    //返回主键字段
    public function primaryKey()
    {
        return $this->primaryKey;
    }

    //返回表名
    public function table()
    {
        return $this->table;
    }

    protected function newBaseQueryBuilder()
    {
        $conn = $this->getConnection();
        $grammar = $conn->getQueryGrammar();

        $queryBuilder = new QueryBuilder(
            $conn, $grammar, $conn->getPostProcessor());
        //将Model对象传入QueryBuilder
        $queryBuilder->setModel($this);

        return $queryBuilder;
    }

    public function newEloquentBuilder($query)
    {
//        $builder = new Builder($query);

//        $builder->macro('key', function (Builder $builder) {
//            return $builder->getQuery()->key();
//        });
//
//        $builder->macro('flush', function (Builder $builder) {
//            return $builder->getQuery()->flush();
//        });

        return $builder;
    }

    //通过主键查内容
    public function getOne($id){
        return $this::where($this->primaryKey, '=', $id)->get();
    }

    //通用保存类方法
    public function saveInfo($saveArr){
        //不存在主键，是新建
        if(empty($saveArr[$this->primaryKey])){
            return $this::create($saveArr);
        }else{
        //否则是修改
            $pkValue = $saveArr[$this->primaryKey];
            unset($saveArr[$this->primaryKey]);
            return $this::where($this->primaryKey, $pkValue)
                        ->update($saveArr);

        }
    }

}
