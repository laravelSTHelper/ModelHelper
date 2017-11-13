<?php namespace Hbclare\ModelHelper;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Cache;

class HelperQueryBuilder extends Builder
{
    /**
     * @var Model
     */
    protected $model;

    protected $_readKey;
    protected $_flushKey;


    //设置Model对象
    public function setHelperModel($model)
    {
        $this->model = $model;
    }

    //读写缓存判断
    protected function needCache()
    {
        //总开关
        if (!$this->model || !$this->model->needCache()) {
            return false;
        }
        //处理缓存key
        $setCacheKey = $this->model->getCacheKeys();
        //$setCacheKey使用完成后，本轮get失效
        $this->model->setCacheKeys('');
        #未设置写入key,尝试处理外键key
        if(empty($setCacheKey)){
            $setCacheKey = $this->getForeignCacheKey();
        }
        #未设置写入key,尝试处理主键key
        if(empty($setCacheKey)){
            $setCacheKey = $this->getPrimaryCacheKey();
        }
        #依旧未设置写入key，不进行缓存处理
        if(empty($setCacheKey)){
            return false;
        }

        $this->_readKey = $this->replaceKey($setCacheKey, 'where');
        return true;
    }

    /**
     * 获取外键缓存key
     * @return array|string
     */
    protected function getForeignCacheKey()
    {
        $setCacheKey = [];
        if(
            true === $this->model->getAutoEachCache() #且自动原子化缓存开启
            && $this->isForeignKeySimple() #且是简单的外键sql
        ){
            #能进入的一定是只有一个外键的情况
            #外键处理
            $foreignKeyArr = $this->model->foreignKey();
            foreach($foreignKeyArr as $foreignKeyK => $foreignKeyV){
                $setCacheKey = $this->model->getPreCacheKeyVersion().'autoForeignCache_'.$this->model->table().'_'
                    .$foreignKeyV.'_{'.$foreignKeyV.'}';
                if($this->replaceKey($setCacheKey, 'where')){
                    #设置缓存时间
                    Cache::setDefaultCacheTime($this->model->getAutoEachCacheTime());
                    break;
                }else{
                    unset($setCacheKey);
                }
            }
        }
        return $setCacheKey;
    }

    /**
     * 设置主键缓存key
     * @return array|string
     */
    protected function getPrimaryCacheKey(){
        $setCacheKey = [];
        if( true === $this->model->getAutoEachCache() #且自动原子化缓存开启
            && $this->isSimple() #且是简单的sql
        ){
            $setCacheKey = $this->model->getPreCacheKeyVersion().'autoCache_'.$this->model->table().'_'
                .$this->model->primaryKey().'_{'.$this->model->primaryKey().'}';
            #设置缓存时间
            Cache::setDefaultCacheTime($this->model->getAutoEachCacheTime());
        }
        return $setCacheKey;
    }

    /**
     * 清理缓存判断
     * @param string $type i insert, u update, d delete
     * @return bool
     */
    protected function needFlushCache($type='u')
    {
        //总开关
        if (!$this->model || !$this->model->needCache()) {
            return false;
        }
        //获取设置的清除key
        $flushKeyArr = $this->model->getFlushKeys();
        //通过where条件替换
        $realWhereFlushKeyArr = $this->formatAutoKey4Where($flushKeyArr);
        //通过修改结果值条件替换
        $realColumnsFlushKeyArr = $this->formatAutoKey4Columns($flushKeyArr);
        //获取自动清除类
        if('i' == $type){
            $autoFlush = $this->model->getAfterInsertFlushKey();
        }else if('u' == $type){
            $autoFlush = $this->model->getAfterUpdateFlushKey();
        }else if('d' == $type){
            $autoFlush = $this->model->getAfterDeleteFlushKey();
        }
        //通过where条件替换
        $realWhereAutoFlushKey = $this->formatAutoKey4Where($autoFlush);
        //通过修改结果值条件替换
        $realColumnsAutoFlushKey = $this->formatAutoKey4Columns($autoFlush);

        $autoForeignKeyCache = [];
        $autoEachCache = [];
        if( true === $this->model->getAutoEachCache()){#透明处理
            if( 'i' !== $type ){ //原子化缓存，插入不处理
                $autoEachCache[] = $this->model->getPreCacheKeyVersion().'autoCache_'.$this->model->table().'_'
                    .$this->model->primaryKey().'_{'.$this->model->primaryKey().'}';
            }
            #外键处理
            $foreignKeyArr = $this->model->foreignKey();
            $autoForeignKeyCache = [];
            foreach($foreignKeyArr as $foreignKeyK => $foreignKeyV){
                $autoForeignKeyCache[] = $this->model->getPreCacheKeyVersion().'autoForeignCache_'.$this->model->table().'_'
                    .$foreignKeyV.'_{'.$foreignKeyV.'}';
            }
        }
        $realForeignKeyCache = $this->formatAutoKey4Columns($autoForeignKeyCache);#透明外键key，考虑参数情况
        $realForeignKeyWhereCache = $this->formatAutoKey4Where($autoForeignKeyCache);#透明外键key，考虑where条件情况
        $realAutoEachCache = $this->formatAutoKey4Where($autoEachCache);#透明主键key，仅仅考虑where条件情况

        $flushKey = array_merge($realWhereFlushKeyArr, $realColumnsFlushKeyArr, $realWhereAutoFlushKey, $realColumnsAutoFlushKey, $realForeignKeyCache, $realForeignKeyWhereCache, $realAutoEachCache);
        if( 0 == count($flushKey) ){
            return false;
        }
        $this->_flushKey = $flushKey;
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
    protected function isAwful()
    {
        $builderObj = $this->getQuery();
        if (self::hasRawColumn($builderObj->columns)) {
            return true;
        }

        return $builderObj->aggregate
        or $builderObj->distinct
        or $builderObj->groups
        or $builderObj->joins
        or $builderObj->orders
        or $builderObj->unions
        or !$builderObj->wheres
        or array_key_exists('Exists', $builderObj->wheres)
        or array_key_exists('InSub', $builderObj->wheres)
        or array_key_exists('NotExists', $builderObj->wheres)
        or array_key_exists('NotInSub', $builderObj->wheres)
        or array_key_exists('Sub', $builderObj->wheres)
        or array_key_exists('raw', $builderObj->wheres);
    }

    /**
     * 「原子查询」就是只根据主键过滤结果集的查询，仅有以下形式：
     * select * from foo where id = 1;
     */
    protected function isSimple()
    {
        if ($this->isAwful() || !$this->getQuery()->wheres || count($this->getQuery()->wheres) > 1){
            return false;
        }
        $whereArr = current($this->getQuery()->wheres);
        if(in_array(strtolower($whereArr['type']), ['nested', 'in'])){
            return false;
        }
        $checkFileds = array();
        $checkFileds[] = $this->model->primaryKey();
        $checkFileds[] = $this->model->table() . '.' . $this->model->primaryKey();
        if (!in_array($whereArr['column'], $checkFileds)) {
            return false;
        }
        if (strtolower($whereArr['type']) === 'basic' && $whereArr['operator'] === '=') {
            return true;
        }
        return false;
    }

    /**
     * 「原子查询」就是只根据外键过滤结果集的查询，仅有以下形式：
     * a: SELECT * FROM `csp_cm_game_img` WHERE `csp_cm_game_img`.`game_id` = '1' and `csp_cm_game_img`.`game_id` IS not NULL
     * b: SELECT * FROM `csp_cm_game_img` WHERE `csp_cm_game_img`.`game_id` = '1'
     */
    protected function isForeignKeySimple()
    {
        if ($this->isAwful() || !$this->getQuery()->wheres || count($this->getQuery()->wheres) > 2) {
            return false;
        }

        if(count($this->getQuery()->wheres) == 2){
            #a形式的sql处理
            #where条件，字段不一致，退出
            #dd($this->getQuery()->wheres[0]);
            if(empty($this->getQuery()->wheres[0]['column']) || empty($this->getQuery()->wheres[1]['column'])){
                return false;
            }
            if($this->getQuery()->wheres[0]['column'] != $this->getQuery()->wheres[1]['column']){
                return false;
            }
            #where 条件处理
            foreach($this->getQuery()->wheres as $wheresKey => $wheresValue){
                if(!$this->checkForeignKey($wheresValue['column'])){
                    return false;
                }
                if(strtolower($wheresValue['type']) != 'basic' &&
                    strtolower($wheresValue['type']) != 'notnull' ){
                    return false;
                }
                if (strtolower($wheresValue['type']) == 'basic' && $wheresValue['operator'] != '=') {
                    return false;
                }
            }
            return true;
        }else if(count($this->getQuery()->wheres) == 1){
            $whereArr = current($this->getQuery()->wheres);
            if(in_array(strtolower($whereArr['type']), ['nested', 'in'])){
                return false;
            }
            if($this->checkForeignKey($whereArr['column'])){
                return true;
            }
            if (strtolower($whereArr['type']) == 'Basic' && $whereArr['operator'] == '=') {
                    return true;
            }
        }
        return false;
    }

    /**
     * 判断查询字段是否是外键
     * @return bool true 是， false 不是
     */
    protected function checkForeignKey($column)
    {
        $checkFileds = array();
        $foreignKey = $this->model->foreignKey();
        foreach($foreignKey as $key => $value){
            $checkFileds[] = $value;
            $checkFileds[] = $this->model->table() . '.' . $value;
        }
        if (!in_array($column, $checkFileds)) {
            return false;
        }
        return true;
    }

    protected static function hasRawColumn($columns)
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

    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $total = $this->query->getCountForPagination();

        $this->query->forPage(
            $page = $page ?: Paginator::resolveCurrentPage($pageName),
            $perPage = $perPage ?: $this->model->getPerPage()
        );

        #设置缓存时间
        if(empty($this->_readKey) && !empty($this->model->getAutoPageCache())){
            $keyPre = $this->model->getPreCacheKeyVersion().'autoPageCache_'.md5($this->toSql().json_encode($this->getBindings()));
            $cacheKey = $keyPre.'_'.$this->model->getPageCacheVer().'_'.$keyPre.'_page_'
                .$page.'_perpage_'.$perPage;
            //dd($cacheKey);
            $this->model->setCacheKeys($cacheKey);
            Cache::setDefaultCacheTime($this->model->getAutoPageCacheTime());
        }


        return new LengthAwarePaginator($this->get($columns), $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    //使用带缓存的方式，执行sql操作
    public function getCacheQuery($columns){
        if($this->_readKey == false){
            throw new \Exception('缓存key为空了，请检查代码！如:setCacheKeys是不是写错字段了');
        }
        #如果缓存不存在
        if(!Cache::has($this->_readKey)){
            $Row = parent::get($columns);
            if(0 != $Row->count()){
                $RowCollect = $Row->toArray();
                Cache::put($this->_readKey, $RowCollect, Cache::getDefaultCacheTime());
            }
            $this->_readKey = array();
            return $Row;
        }else{
            $res = Cache::get($this->_readKey);
            //清空需要读取的缓存key
            $this->_readKey = array();
            //重新组装model对象
            $resData = [];
            //保存已经生产出的对象
            global $cacheModel;
            //d($this->model);
            //dump($this->model->attributesToArray());
            foreach($res as $key => $value){
                $modelCacheKey = '';
                $modelCacheKey = md5($this->model->table().json_encode($value));
                //如果对象存在数据，进入clone对象流程
                if(!empty($cacheModel[$modelCacheKey])) {
                    $newModel = $cacheModel[$modelCacheKey];
                }else{
                    $newModel = empty($this->model->getAttributes()) ?
                                clone $this->model                  :
                                $this->model                        ;
                    $newModel->exists = true;
                    $newModel->setRawAttributes($value, true);
                    $cacheModel[$modelCacheKey] = $newModel;
                }
                $resData[] = $newModel;
            }
            return collect($resData);
        }
    }

    //重写insert动作，让insert带上清理缓存的连带动作
    public function insert(array $values)
    {
        $Row = parent::insert($values);
        //清理缓存动作置后，解决大并发下提再次生成脏数据bug
        $this->model->setColumnsArr($values);
        if ($this->needFlushCache('i')) {
            $this->cleanKeys();
        }
        $this->model->cleanColumnsArr();
        //判断是否开启分页缓存,开启则清空
        if($this->model->getAutoPageCache()){
            $this->model->flushPageCache();
        }
        return $Row;
    }

    public function insertGetId(array $values, $sequence = null)
    {
        $Row = parent::insertGetId($values, $sequence);
        //清理缓存动作置后，解决大并发下提再次生成脏数据bug
        $this->model->setColumnsArr($values);
        if ($this->needFlushCache('i')) {
            $this->cleanKeys();
        }
        $this->model->cleanColumnsArr();
        //判断是否开启分页缓存,开启则清空
        if($this->model->getAutoPageCache()){
            $this->model->flushPageCache();
        }

        return $Row;
    }

    //重写update动作，让update带上清理缓存的连带动作
    public function update(array $values)
    {
        $Row = parent::update($values);
        //清理缓存动作置后，解决大并发下提再次生成脏数据bug
        $this->model->setColumnsArr($values);
        if ($this->needFlushCache('u')) {
            $this->cleanKeys();
        }
        $this->model->cleanColumnsArr();
        //判断是否开启分页缓存,开启则清空
        if($this->model->getAutoPageCache()){
            $this->model->flushPageCache();
        }
        return $Row;
    }


    //重写delete动作，让delete带上清理缓存的连带动作
    public function delete($id = null)
    {
        $Row = parent::delete($id);
        //清理缓存动作置后，解决大并发下提再次生成脏数据bug
        if ($this->needFlushCache('d')) {
            $this->cleanKeys();
        }
        //判断是否开启分页缓存,开启则清空
        if($this->model->getAutoPageCache()){
            $this->model->flushPageCache();
        }
        return $Row;
    }


    /**
     * 通过Where条件，格式化自动执行Key，在update，delete操作使用
     * @param $cacheKeys Array 缓存处理
     */
    protected function formatAutoKey4Where($cacheKeyArr){
        $realKeyArr = array();
        foreach($cacheKeyArr as $key => $value){
            $realKey = $this->replaceKey($value, 'where');
            !empty($realKey) && $realKeyArr[] = $realKey;
        }
        return $realKeyArr;
    }

    /**
     * 通过修改结果值，格式化自动执行Key，在update，instert操作使用
     * @param $cacheKeys Array 缓存处理
     */
    protected function formatAutoKey4Columns($cacheKeyArr){
        $realKeyArr = array();
        foreach($cacheKeyArr as $key => $value){
            $realKey = $this->replaceKey($value, 'columns');
            !empty($realKey) && $realKeyArr[] = $realKey;
        }
        return $realKeyArr;
    }

    /**
     * 替换key中的通配符，生成完整的
     * @param $cacheKey
     * @return false or 字段数组
     */
    protected function replaceKey($cacheKey, $type){
        $result = array();
        //匹配括号里面的值
        preg_match_all("/(?:\{)(.*)(?:\})/iU", $cacheKey, $result);
        //没有{}直接返回
        if(empty($result[0])){
            return $cacheKey;
        }
        $searchArr = $repaceArr = array();//初始化
        //字段对比
        //dump($result);
        foreach($result[1] as $key => $value){
            if('where' == $type){
                $findValue = $this->findWhereValue($value);
            }else{
                $findValue = $this->findColumnsValue($value);
            }
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
        $originWheres = $this->getQuery()->wheres;
        //获取表名
        $tablename = $this->model->table();
        foreach((array)$originWheres as $whereKey => $whereValue){
            if( !empty($whereValue['operator'])  && '=' == $whereValue['operator'] &&
                ($tablename.'.'.$value == $whereValue['column'] || $value == $whereValue['column'])){
//                dump($whereValue['value']);
                return $whereValue['value'];
            }
            if (!empty($whereValue['query']->wheres)) {
                foreach ($whereValue['query']->wheres as $wheresKey => $wheresValue) {
                    if (!empty($wheresValue['operator']) && '=' == $wheresValue['operator'] &&
                        ($tablename . '.' . $value == $wheresValue['column'] || $value == $wheresValue['column'])
                    ) {
                        return $wheresValue['value'];
                    }
                }
            }
        }
        return false;
    }

    /**
     * 查询值匹配
     * @param $value
     * @return bool
     */
    public function findColumnsValue($keyName){
        //获取字段
        $originColunms = $this->model->getColumnsArr();
        //获取表名
        if(empty($originColunms)){
            return false;
        }
        foreach($originColunms as $colunmKey => $colunmValue){
            if( $keyName == $colunmKey){
                return $colunmValue;
            }
        }
        return false;
    }

    /**
     * 清理缓存方法
     */
    protected function cleanKeys(){
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


    /**
     * 查找一条数据
     * @param array $where eg:['id'=>1,'fild'=>2]
     * @param array $orderBy eg：['id'=>'desc']
     * @return mixed
     */
    public function findOne(array $where, $orderBy = [])
    {
        $this->formatWhere($where);
        if (!empty($orderBy)) {
            $this->formatOrderBy($orderBy);
        }
        $res = $this->first();
        if( is_array($res) ){
            $this->model->exists = true;
            $this->model->setRawAttributes($res, true);
            return $this->model;
        }
        return $res;
    }

    /**
     * 通过主键查内容
     * @param $id
     * @return mixed
     */
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
    public function getList($where, $orderBy = [], $take = null, $skip = null ,$fields = '*')
    {
        $this->formatWhere($where)->select($fields);
        if (!empty($orderBy)) {
            $this->formatOrderBy($orderBy);
        }
        if (!empty($take)) {
            $this->take($take);
        }
        if (!empty($skip)) {
            $this->skip($skip);
        }
        return $this->get();
    }

    /**
     * 高级List方法，一般用来统计
     * @param $where
     * @param $predicate
     * @param string $fields
     * @return mixed
     */
    public function getListUpgraded($where=[], $predicate=[], $fields='*'){
        $this->formatWhere($where)->select($fields);
        $this->formatPredicate($predicate);
        return $this->get();
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
        $this->formatWhere($where);
        if (!empty($orderBy)) {
            $this->formatOrderBy($orderBy);
        }
        return $this->paginate($pageNum);
    }
    /**
     * 获取分页列表数据高级方法
     * @param $where
     * @param array $orderBy
     * @param $pageNum
     * @return mixed
     */
    public function getPaginateListUpgraded($where, $pageNum = 20, $predicate, $fields='*')
    {
        $this->formatWhere($where)->select($fields);
        $this->formatPredicate($predicate);
        return $this->paginate($pageNum);
    }
    /**
     * 格式化谓词数组为orm
     * @param $predicate
     * @return mixed
     */
    public function formatPredicate($predicate){
        foreach($predicate as $key => $value){
            $key = strtolower($key);
            if( 'groupby' == $key ){# groupBy 的 value,支持字符串 'name' ，或者数组 [ 'name', 'sex' ]
                $this->groupBy($value);
            }
            if('having' == $key){#必须是数组[$column, $operator = null, $value = null, $boolean = 'and']
                $this->having($value[0], is_set($value[1])?$value[1]:null, is_set($value[2])?$value[2]:null, is_set($value[3])?$value[3]:'and');
            }
            if( 'orderby' == $key ){# orderby 的 value为数组 ['id'=>'desc']
                $this->formatOrderBy($value);
            }
            if ( 'skip' == $key) {#起始值，从0开始
                $this->skip($value);
            }
            if ( 'take' == $key) {#获得的条数
                $this->take($value);
            }
        }
        return $this;
    }

    /**
     * 格式条件语句
     * @param $where
     * @return mixed
     */
    public function formatWhere($where)
    {
        $this->where(function ($query) use ($where) {
            if (!empty($where)) {
                foreach ($where as $key => $value) {
                    if (is_array($value)) {
                        switch(strtolower($value[0])){
                            case 'in':
                                $query->whereIn($key,$value[1]);break; //$value[1]为数组，示例：['a','b','c']
                            case 'notin':
                                $query->whereNotIn($key,$value[1]);break;
                            case 'between':
                                $query->whereBetween($key,$value[1]);break;//$value[1]为数组，示例：[$start_val,$end_val]
                            case 'notbetween':
                                $query->whereNotBetween($key,$value[1]);break;//$value[1]为数组，示例：[$start_val,$end_val]
                            case 'or'://$value[1]为数组，示例：['like','%'.$name.'%']
                                $query->orWhere(function($query) use ($key, $value){
                                    $this->orWhere($key, $value[1][0], $value[1][1]);
                                });break;
                            case 'raw':
                                $query->whereRaw($value[1]);break;//$value[1]为字符串，示例：(`column_id` = '0102' or `column_id` = 'ALL' and `start_time` <> 0)
                            default:
                                $query->where($key, $value[0], $value[1]);break;
                        }
                    } else {
                        $query->where($key, $value);
                    }
                }
            }
        });
        return $this;
    }

    /**
     * 格式排序语句
     * @param array $orderBy
     * @return mixed
     */
    public function formatOrderBy($orderBy)
    {
        if (is_array($orderBy)) {
            foreach ($orderBy as $key => $value) {
                if ( strtolower($value) !== 'desc' && strtolower($value) !== 'asc') {
                    continue;
                }
                $this->orderBy($key, $value);
            }
        }
        return $this;
    }

    /**
     * 根据条件计算条数
     * @return mixed
     */
    public function getCount($where, $field='*')
    {
        return $this->formatWhere($where)->count($field);
    }

    /**
     * 得到条件下的字段求和的值
     * @param $where
     * @param $field
     * @return mixed
     */
    public function getSum($where, $field)
    {
        return $this->formatWhere($where)->sum($field);
    }

    /**
     * 得到条件的最大值
     * @param $where
     * @param $field
     * @return mixed
     */
    public function getMax($where, $field)
    {
        return $this->formatWhere($where)->max($field);
    }

    /**
     * 得到条件的最小值
     * @param $where array
     * @param $field string
     * @return mixed
     */
    public function getMin($where, $field)
    {
        return $this->formatWhere($where)->min($field);
    }


}
