<?php

namespace Hbclare\ModelHelper\Console;

use Illuminate\Console\Command;
use Hbclare\ModelHelper\Console\GeneratorCommand;

class MakeRepositoryCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:repository {repositoryName}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'create eachmodel file';

    /**
     * Create a new command instance.
     *
     * @return void
     */
//    public function __construct()
//    {
//        parent::__construct();
//    }

    public function getStub($interface)
    {
        if(!empty($interface)){
            return __DIR__ . '/stubs/repositoryinterface.stub';
        }else{
            return __DIR__ . '/stubs/repository.stub';
        }
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $repositoryName = $this->argument('repositoryName');

        $repositoryNameInterface = $this->createInterface($repositoryName);
        $this->type = $repositoryName;
        parent::handel($repositoryName);
        $this->type = $repositoryNameInterface;
        parent::handel($repositoryNameInterface, 1);
    }

    /**
     * @param $repositoryName
     * @return string
     */
    public function createInterface($repositoryName){
        if(false == stripos($repositoryName, '/')){
            $repositoryInterfaceName = $repositoryName.'Interface';
        }else{
            $nameArr = explode('/', $repositoryName);
            $arrCount = count($nameArr);
            $repositoryInterfaceName = '';
            foreach($nameArr as $key => $value){
                $repositoryInterfaceName .= $value;
                if($arrCount - 1 == $key || 0 == $key ){
                    $repositoryInterfaceName .= 'Interface';
                }
                $repositoryInterfaceName .= '/';
            }
        }
        $repositoryInterfaceName = substr($repositoryInterfaceName, 0, -1);
        return $repositoryInterfaceName;
    }



}
