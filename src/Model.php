<?php namespace Hbclare\ModelHelper;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;

abstract class Model extends EloquentModel
{
    //是否开启Model缓存总开关，false关闭，ture开启
    protected $endisabledCache = true;

    //是否开启默透明缓存
    protected $autoEachCache = false;

    //需要缓存的key，在一次get()流程中生效
    protected $cacheKey = '';
    //需要清理的缓存名字
    protected $flushKey = array();


    //开启自动原子化缓存
    protected function startAutoEachCache()
    {
        $this->autoEachCache = true;
    }

    //关闭自动原子化缓存
    protected function stopAutoEachCache()
    {
        $this->autoEachCache = false;
    }

    public function getAutoEachCache()
    {
        return $this->autoEachCache;
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
    public function setCacheKeys($key)
    {
        $this->cacheKey = $key;
    }

    public function getCacheKeys()
    {
        return $this->cacheKey;
    }

    //设置/读取 需删除缓存key
    //需删除缓存，在redis驱动下，支持*,?通配符匹配批量删除
    public function setFlushKeys($key)
    {
        !is_array($key) ?
            $keyArr[] = $key :
            $keyArr = $key;
        $this->flushKey = $keyArr;
    }

    public function getFlushKeys()
    {
        return $this->flushKey;
    }

    //设置/读取 插入操作后删除缓存key
    public function setAfterInsertFlushKey($key)
    {
        !is_array($key) ?
            $keyArr[] = $key :
            $keyArr = $key;
        $this->afterInsertFlushKey = $keyArr;
    }

    public function getAfterInsertFlushKey()
    {
        return $this->afterInsertFlushKey;
    }

    //设置/读取 更新操作后删除缓存key
    public function setAfterUpdateFlushKey($key)
    {
        !is_array($key) ?
            $keyArr[] = $key :
            $keyArr = $key;
        $this->afterUpdateFlushKey = $keyArr;
    }

    public function getAfterUpdateFlushKey()
    {
        return $this->afterUpdateFlushKey;
    }


    //设置/读取 删除操作后删除缓存key
    public function setAfterDeleteFlushKey($key)
    {
        !is_array($key) ?
            $keyArr[] = $key :
            $keyArr = $key;
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
        $builder = new Builder($query);

//        $builder->macro('key', function (Builder $builder) {
//            return $builder->getQuery()->key();
//        });
//
//        $builder->macro('flush', function (Builder $builder) {
//            return $builder->getQuery()->flush();
//        });

        return $builder;
    }

    /**
     * 查找一条数据
     * @param array $where eg:['id'=>1,'fild'=>2]
     * @param array $orderBy eg：['id'=>'desc']
     * @return mixed
     */
    public function findOne(array $where, $orderBy = [])
    {
        $queryObj = $this->formatWhere($where);
        if (!empty($orderBy)) {
            $queryObj = $this->formatOrderBy($queryObj, $orderBy);
        }
        return $queryObj->first();
    }

    //通过主键查内容
    public function getOne($id)
    {
        return $this->find($id);
    }

    /**
     * 获取列表数据
     * @param array $where
     * @param array $orderBy eg：['id'=>'desc']
     * @param null $limit
     */
    public function getList($where, $orderBy = [], $take = null, $skip = null)
    {
        $queryObj = $this->formatWhere($where);
        if (!empty($orderBy)) {
            $queryObj = $this->formatOrderBy($queryObj, $orderBy);
        }
        if (!empty($take)) {
            $queryObj->take($take);
        }
        if (!empty($skip)) {
            $queryObj->skip($skip);
        }
        return $queryObj->get();
    }

    /**
     * 获取分页列表数据
     * @param $where
     * @param array $orderBy
     * @param $pageNum
     * @return mixed
     */
    public function getPaginateList($where, $orderBy = [], $pageNum = 20)
    {
        $queryObj = $this->formatWhere($where);
        if (!empty($orderBy)) {
            $queryObj = $this->formatOrderBy($queryObj, $orderBy);
        }
        return $queryObj->paginate($pageNum);
    }

    /**
     * 格式条件语句
     * @param $where
     * @return mixed
     */
    private function formatWhere($where)
    {
        $queryObj = $this->where(function ($query) use ($where) {
            if (!empty($where)) {
                foreach ($where as $key => $value) {
                    if(is_array($value)){
                        $query->where($key, $value[0], $value[1]);
                    }else{
                        $query->where($key, $value);
                    }
                }
            }
        });
        return $queryObj;
    }

    /**
     * 格式排序语句
     * @param $queryObj
     * @param array $orderBy
     * @return mixed
     */
    private function formatOrderBy($queryObj, $orderBy)
    {
        if (is_array($orderBy)) {
            foreach ($orderBy as $key => $value) {
                if ($value !== 'desc' && $value !== 'asc') {
                    continue;
                }
                $queryObj->orderBy($key, $value);
            }
        }
        return $queryObj;
    }

    //通用保存类方法
    public function saveInfo($saveArr)
    {
        //不存在主键，是新建
        if (empty($saveArr[$this->primaryKey])) {
            return $this::create($saveArr);
        } else {
            //否则是修改
            $pkValue = $saveArr[$this->primaryKey];
            unset($saveArr[$this->primaryKey]);
            return $this::where($this->primaryKey, $pkValue)
                ->update($saveArr);
        }
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
        foreach ($data as $val) {
            $this->destroy($val->id);
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

}
