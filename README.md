# ModelHelper

##交流群:370087262
对laravel Eloquent OMR 的一层带缓存的封装
参考了https://github.com/angejia/pea.git 的缓存层实现
参考了https://github.com/aaronjan/housekeeper 的artisan的实现

##封装的目的
在laravel的使用过程中，发现很多对model层缓存的透明化封装，而这些封装主要是对所有的sql语句进行了缓存，如果有数据的更新或者删除，则对数据进行了全部删除，做的好一些的，就是对表级数据，进行了删除。
在实际使用过程中，特别是web类，面向用户的操作，更多的只是简单的select操作，如果我们将这些简单的select单条查询存入缓存，将连表操作改成 select一张表中的数据list，然后foreach 该list，循环取另一张表的info类型，我们可以避免80%以上的连表操作。
但是，这种做法，对缓存控制要求就很高了，对于缓存脏数据的清理，我们希望更精准，谁脏了，就干掉谁，而不是批量处理的做法，故封装了此扩展。


##用法
见DOC