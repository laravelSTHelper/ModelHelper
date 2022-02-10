<?php namespace Hbclare\ModelHelper;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Cache;

abstract class Model extends EloquentModel
{
	//设置当前版本，如果遇到大的改动可以自动让缓存失效，而不用手动清理缓存
	protected $preCacheKeyVersion = 'v1.2.23_';

    //是否开启Model缓存总开关，false关闭，ture开启
    protected $endisabledCache = true;

    //是否开启默透明缓存
    protected $autoEachCache = false;
    protected $autoEachCacheTime;

    //是否开启自动分页缓存
    protected $autoPageCache = false;
    //默认缓存，30分钟
    protected $autoPageCacheTime;

    //需要缓存的key，在一次get()流程中生效
    protected $cacheKey = '';
    //需要清理的缓存名字
    protected $flushKey = array();
    //修改的值
    protected $columnsArr = array();

    //开启总缓存
    protected function startCache()
    {
        $this->endisabledCache = true;
    }

    //关闭总缓存
    protected function stopCache()
    {
        $this->endisabledCache = false;
    }

    //开启自动原子化缓存
    //默认缓存30分钟
    protected function startAutoEachCache($cacheTime=30)
    {
        $this->autoEachCacheTime = $cacheTime;
        $this->autoEachCache = true;
    }

    //关闭自动原子化缓存
    protected function stopAutoEachCache()
    {
        $this->autoEachCache = false;
    }

    //开启自动分页缓存
    //默认缓存5分钟
    protected function startAutoPageCache($cacheTime=5)
    {
        $this->autoPageCacheTime = $cacheTime;
        $this->autoPageCache = true;
    }

    //关闭自动分页缓存
    protected function stopAutoPageCache()
    {
        $this->autoPageCache = false;
    }
    //得到原子化缓存状态
    public function getAutoEachCache()
    {
        return $this->autoEachCache;
    }

    //得到原子化缓存时间
    public function getAutoEachCacheTime()
    {
        return $this->autoEachCacheTime;
    }

    //得到当前版本
    public function getPreCacheKeyVersion()
    {
        return $this->preCacheKeyVersion;
    }

    //得到分页缓存状态
    public function getAutoPageCache()
    {
        return $this->autoPageCache;
    }
    //得到分页缓存时间
    public function getAutoPageCacheTime()
    {
        return $this->autoPageCacheTime;
    }
    //等到分页缓存版本存储key
    public function getPageCacheVerKey(){
        return $this->preCacheKeyVersion.'pageCacheVer_'.$this->table();
    }
    //得到分页缓存版本
    public function getPageCacheVer(){
        $pageVersionKey = $this->getPageCacheVerKey();
        if(!Cache::has($pageVersionKey)){
            $pageCacheVer = 1;
        }else{
            $pageCacheVer = Cache::get($pageVersionKey);
        }
        return $pageCacheVer;
    }

    //设置/读取 更新时候的值
    public function setColumnsArr(array $columnsArr)
    {
        $this->columnsArr = $columnsArr;
    }

    public function getColumnsArr()
    {
        return $this->columnsArr;
    }

    public function cleanColumnsArr()
    {
        unset($this->columnsArr);
        return true;
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

    public function __construct(array $attributes)
    {
        parent::__construct($attributes);
    }

    //设置/读取 需设置缓存key
    public function setCacheKeys($key)
    {
		if(empty($key)){
            $this->cacheKey = '';
		}else{
			//设置缓存，增加版本
			$this->cacheKey = $this->preCacheKeyVersion.$key;
		}
    }

    public function getCacheKeys()
    {
        return $this->cacheKey;
    }

    //设置/读取 需删除缓存key
    public function setFlushKeys($key)
    {
		if(!is_array($key)){
            $keyArr[] = $this->preCacheKeyVersion.$key;
		}else{
			foreach($key as $k){
				$keyArr[] = $this->preCacheKeyVersion.$k;
			}
		}
        $this->flushKey = array_merge($this->flushKey, $keyArr);
    }

    public function getFlushKeys()
    {
        return $this->flushKey;
    }

    //设置/读取 插入操作后删除缓存key
    public function setAfterInsertFlushKey($key)
    {
		if(!is_array($key)){
            $keyArr[] = $this->preCacheKeyVersion.$key;
		}else{
			foreach($key as $k){
				$keyArr[] = $this->preCacheKeyVersion.$k;
			}
		}
        $this->afterInsertFlushKey = $keyArr;
    }

    public function getAfterInsertFlushKey()
    {
        return $this->afterInsertFlushKey;
    }

    //设置/读取 更新操作后删除缓存key
    public function setAfterUpdateFlushKey($key)
    {
		if(!is_array($key)){
            $keyArr[] = $this->preCacheKeyVersion.$key;
		}else{
			foreach($key as $k){
				$keyArr[] = $this->preCacheKeyVersion.$k;
			}
		}
        $this->afterUpdateFlushKey = $keyArr;
    }

    public function getAfterUpdateFlushKey()
    {
        return $this->afterUpdateFlushKey;
    }


    //设置/读取 删除操作后删除缓存key
    public function setAfterDeleteFlushKey($key)
    {
		if(!is_array($key)){
            $keyArr[] = $this->preCacheKeyVersion.$key;
		}else{
			foreach($key as $k){
				$keyArr[] = $this->preCacheKeyVersion.$k;
			}
		}
        $this->afterDeleteFlushKey = $keyArr;
    }

    public function getAfterDeleteFlushKey()
    {
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

    //返回外键值
    public function foreignKey(){
        if(empty($this->foreignKeyArr)){
            return array();
        }
        return $this->foreignKeyArr;
    }

    public function newEloquentBuilder($query)
    {
        $builder = new HelperQueryBuilder($query);

//        $builder->macro('key', function (Builder $builder) {
//            return $builder->getQuery()->key();
//        });
//
//        $builder->macro('flush', function (Builder $builder) {
//            return $builder->getQuery()->flush();
//        });

        return $builder;
    }


    //通用保存类方法
    public function saveInfo($saveArr)
    {
        if(!empty($saveArr[$this->primaryKey])){
            //将主键传入syncOriginal
            $keyArr[$this->primaryKey] = $saveArr[$this->primaryKey];
            //设置当前需要清理的外键缓存
            $data = $this->formatWhere([$this->primaryKey=>$saveArr[$this->primaryKey]])->get();
            $foreignKeys = $this->foreignKey();
            //外键直接设置清理
            foreach ($data as $val) {
                foreach ($foreignKeys as $foreignVal) {
                    if(!empty($val->$foreignVal)){
                        $flushKey = $this->preCacheKeyVersion.'autoForeignCache_'.$this->table().'_'
                            .$foreignVal.'_'.$val->$foreignVal;
                        $this->setFlushKeys($flushKey);
                    }
                }
            }
            $res = $this->find($keyArr[$this->primaryKey]);#先查找ORM对象
            $this->setRawAttributes($res->getOriginal(), true);#将查询数据赋值给syncOriginal
            $this->exists = true;
            //unset($saveArr[$this->primaryKey]);
        }else{
            $this->setRawAttributes($saveArr, true);#刻意将主键不给syncOriginal
            $this->exists = false;
        }
        $this->fill($saveArr);
        return parent::save($saveArr);
    }

    /**
     * 删除数据，一条条根据逐渐删除
     * @param $where
     * @return bool
     * @throws \Exception
     */
    public function delCleanCache($where)
    {
        $data = $this->formatWhere($where)->get();
        if (empty($data) || $data->count() == 0) {
            return FALSE;
        }
        $primaryKey = $this->primaryKey();
        //$foreignKeys = $this->foreignKey();
        //这里直接处理所有的可修改字段
        $fillable = $this->getFillable();
        foreach ($data as $val) {
            $whereDel[$primaryKey] = $val->$primaryKey;
            //所有的可以修改字段，也做条件处理
            foreach ($fillable as $fillableVal) {
                $whereDel[$fillableVal] = $val->$fillableVal;
            }
            $this->del($whereDel);
        }
        return TRUE;
    }


    /**
     * 根据条件删除数据
     * @param $where
     * @return mixed
     */
    public function del($where)
    {
        return $this->formatWhere($where)->delete();
    }

    /**
     * 更新当前表的所有分页缓存
     */
    public function flushPageCache(){
        $pageVersionKey = $this->getPageCacheVerKey();
        Cache::put($pageVersionKey, time(), rand(60, 600));#设置版本
    }
}
