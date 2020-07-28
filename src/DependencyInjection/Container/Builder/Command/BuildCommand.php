<?php

declare(strict_types=1);

namespace LDL\DependencyInjection\Container\Builder\Console\Command;

use LDL\DependencyInjection\CompilerPass\Finder\CompilerPassFinder;
use LDL\DependencyInjection\CompilerPass\Finder\Options\CompilerPassFinderOptions;
use LDL\DependencyInjection\CompilerPass\Reader\CompilerPassReader;
use LDL\DependencyInjection\CompilerPass\Reader\Options\CompilerPassReaderOptions;
use LDL\DependencyInjection\Container\Compiler\ServiceCompiler;
use LDL\DependencyInjection\Container\Compiler\Options\ServiceCompilerOptions;

use LDL\DependencyInjection\Service\Finder\ServiceFileFinder;
use LDL\DependencyInjection\Service\Finder\Options\ServiceFileFinderOptions;

use LDL\DependencyInjection\Service\Reader\ServiceFileReader;
use LDL\DependencyInjection\Service\Reader\Options\ServiceReaderOptions;
use LDL\DependencyInjection\Container\Writer\ContainerFileWriter;

use LDL\DependencyInjection\Container\Builder\Builder;

use LDL\DependencyInjection\Container\Writer\Options\ContainerWriterOptions;

use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BuildCommand extends SymfonyCommand
{
    public const COMMAND_NAME = 'container:build';

    public function configure() : void
    {
        $finderDefaults = ServiceFileFinderOptions::fromArray([]);
        $compilerDefaults = ServiceCompilerOptions::fromArray([]);
        $cpassDefaults = CompilerPassFinderOptions::fromArray([]);

        $this->setName(self::COMMAND_NAME)
            ->setDescription('Build compiled services.xml file')
            ->addArgument(
                'output-file',
                InputArgument::REQUIRED,
                'Name of the output file'
            )
            ->addArgument(
                'dump-format',
                InputArgument::OPTIONAL,
                'Dump container in a specific format',
                $compilerDefaults->getDumpFormat()
            )
            ->addOption(
                'force-overwrite',
                'w',
                InputOption::VALUE_NONE,
                'Overwrite output file'
            )
            ->addOption(
                'scan-directories',
                'd',
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    'Comma separated list of directories to scan, default: %s',
                    implode(', ', $finderDefaults->getDirectories())
                ),
                implode(',', $finderDefaults->getDirectories())
            )
            ->addOption(
                'scan-files',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Comma separated list of files to scan',
                implode(', ', $finderDefaults->getFiles())
            )
            ->addOption(
                'find-first',
                'f',
                InputOption::VALUE_REQUIRED,
                'Comma separated list of files of service files to be loaded with first priority'
            )
            ->addOption(
                'ignore-read-errors',
                'i',
                InputOption::VALUE_NONE,
                'Ignore syntax errors in service files'
            )
            ->addOption(
                'dump-options',
                'j',
                InputOption::VALUE_OPTIONAL,
                'Dump string options in json format'
            )
            ->addOption(
                'cpass-pattern',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Find files matching a regex pattern to find compiler pass files',
                $cpassDefaults->getPattern()
            );
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->build($input, $output);
            return 0;
        }catch(\Exception $e){
            $output->writeln("<error>{$e->getMessage()}</error>");
            return 1;
        }
    }

    private function build(
        InputInterface $input,
        OutputInterface $output
    ) : void
    {
        $start = hrtime(true);
        $findFirst = $input->getOption('find-first');

        try{
            $dumpOptions = $input->getOption('dump-options');

            $compilerProgress = new ProgressBar($output);
            $compilerProgress->setOverwrite(true);

            $writerOptions = ContainerWriterOptions::fromArray([
                'filename' => $input->getArgument('output-file'),
                'force' => (bool) $input->getOption('force-overwrite')
            ]);

            $finderOptions = ServiceFileFinderOptions::fromArray([
                'directories' => explode(',', $input->getOption('scan-directories')),
                'files' => explode(',', $input->getOption('scan-files')),
                'findFirst' => null !== $findFirst ? explode(',', $findFirst) : []
            ]);

            $readerOptions = ServiceReaderOptions::fromArray([
                'ignoreErrors' => (bool)$input->getOption('ignore-read-errors')
            ]);

            $compilerPassFinderOptions = CompilerPassFinderOptions::fromArray([
                'pattern' => $input->getOption('cpass-pattern'),
                'directories' => explode(',', $input->getOption('scan-directories'))
            ]);

            $compilerPassReaderOptions = CompilerPassReaderOptions::fromArray([
                'ignoreErrors' => (bool)$input->getOption('ignore-read-errors')
            ]);

            if(null !== $dumpOptions){
                $dumpOptions = json_decode($dumpOptions, true);

                if(json_last_error() !== JSON_ERROR_NONE){
                    throw new \RuntimeException("Invalid json format");
                }
            }

            $compilerOptions = ServiceCompilerOptions::fromArray([
                'dumpFormat' => $input->getArgument('dump-format'),
                'dumpOptions' => $dumpOptions ?? [],
                'onBeforeCompile' => function($container, $files) use ($compilerProgress){
                    $compilerProgress->setMaxSteps(count($files));
                },
                'onCompile' => function($container, $file) use ($compilerProgress){
                    $compilerProgress->advance();
                },
                'onAfterCompile' => function($file, $vars) use ($compilerProgress){
                    $compilerProgress->finish();
                }
            ]);

            $title = '[ Building compiled services file ]';

            $output->writeln("\n<info>$title</info>\n");

            $builder = new Builder(
                new ServiceFileFinder($finderOptions),
                new ServiceCompiler($compilerOptions),
                new ContainerFileWriter($writerOptions),
                new ServiceFileReader($readerOptions),
                new CompilerPassFinder($compilerPassFinderOptions),
                new CompilerPassReader($compilerPassReaderOptions)
            );

            $builder->build();

            $output->writeln("");

        }catch(\Exception $e) {

            $output->writeln("\n\n<error>Build failed!</error>\n");
            $output->writeln("{$e->getMessage()}");
            $output->writeln("Scanned directories: {$input->getOption('scan-directories')}");
            $output->writeln("Scanned files: {$input->getOption('scan-files')}");

        }

        $end = hrtime(true);
        $total = round((($end - $start) / 1e+6) / 1000,2);

        $output->writeln("\n<info>Took: $total seconds</info>");
    }

}