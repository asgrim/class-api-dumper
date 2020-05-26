<?php

declare(strict_types=1);

namespace Asgrim\ClassApiDumper\Command;

use ReflectionMethod;
use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Reflection\ReflectionClass;
use Roave\BetterReflection\Reflection\ReflectionParameter;
use Roave\BetterReflection\Reflector\ClassReflector;
use Roave\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\AutoloadSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\DirectoriesSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\EvaledCodeSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\MemoizingSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\PhpInternalSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\SourceLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webmozart\Assert\Assert;
use function array_filter;
use function array_map;
use function array_values;
use function implode;
use function realpath;
use function sprintf;
use function str_repeat;
use function strlen;
use function trim;

final class DumpClassApi extends Command
{
    public const COMMAND_NAME = 'dump-class-api';

    public function configure() : void
    {
        $this
            ->setName(self::COMMAND_NAME)
            ->setDescription('Dump a summary of the class API of PHP classes in a directory')
            ->setDefinition(
                new InputDefinition([new InputArgument('path', InputArgument::REQUIRED, 'The path to analyse')])
            );
    }

    /**
     * @return string[]
     *
     * @psalm-return list<class-string>
     */
    private function listPublicClassesInSourceLocator(SourceLocator $sourceLocator) : array
    {
        $reflector = new ClassReflector($sourceLocator);

        return array_values(array_map(
            static function (ReflectionClass $reflectionClass) : string {
                return $reflectionClass->getName();
            },
            array_filter(
                $reflector->getAllClasses(),
                static function (ReflectionClass $reflectionClass) : bool {
                    return ! $reflectionClass->isInternal();
                }
            )
        ));
    }

    public function execute(InputInterface $input, OutputInterface $output) : int
    {
        $path = $input->getArgument('path');
        Assert::stringNotEmpty($path);

        $realPath = realpath($path);
        Assert::stringNotEmpty($realPath);

        $formatter = $output->getFormatter();
        $formatter->setStyle('class', new OutputFormatterStyle('green', 'black', ['bold']));
        $formatter->setStyle('method', new OutputFormatterStyle('green', 'black', []));

        $betterReflection  = new BetterReflection();
        $pathSourceLocator = new DirectoriesSourceLocator([$realPath], $betterReflection->astLocator());
        $astLocator        = $betterReflection->astLocator();
        $sourceStubber     = $betterReflection->sourceStubber();

        $reflector = new ClassReflector(new MemoizingSourceLocator(new AggregateSourceLocator([
            $pathSourceLocator,
            new PhpInternalSourceLocator($astLocator, $sourceStubber),
            new EvaledCodeSourceLocator($astLocator, $sourceStubber),
            new AutoloadSourceLocator($astLocator, $betterReflection->phpParser()),
        ])));

        $banner = sprintf('List of classes and their public API in : %s', $realPath);
        $output->writeln(str_repeat('=', strlen($banner)));
        $output->writeln($banner);
        $output->writeln(str_repeat('=', strlen($banner)));
        $output->writeln('');

        foreach ($this->listPublicClassesInSourceLocator($pathSourceLocator) as $fqcn) {
            $reflectionClass = $reflector->reflect($fqcn);
            $output->writeln(sprintf('<class>%s</class>', $reflectionClass->getName()));

            foreach ($reflectionClass->getImmediateMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
                $output->writeln(sprintf(
                    '  %s<method>%s</method>(%s) : %s',
                    $reflectionMethod->isStatic() ? '::' : '->',
                    $reflectionMethod->getName(),
                    implode(
                        ', ',
                        array_map(
                            static function (ReflectionParameter $reflectionParameter) : string {
                                return trim(sprintf(
                                    '%s $%s',
                                    (string) $reflectionParameter->getType(),
                                    $reflectionParameter->getName()
                                ));
                            },
                            $reflectionMethod->getParameters()
                        )
                    ),
                    $reflectionMethod->hasReturnType() ? (string) $reflectionMethod->getReturnType() : 'mixed'
                ));
            }
        }

        $output->writeln('');

        return 0;
    }
}
