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

    public function getStub()
    {
        // TODO: Implement getStub() method.
        return __DIR__ . '/stubs/repository.stub';
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $modelName = $this->argument('repositoryName');

        parent::handel($modelName);
    }
}
