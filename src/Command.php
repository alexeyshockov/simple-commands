<?php

namespace PlainCommands;

use InvalidArgumentException;
use PhpOption\None;
use PhpOption\Option;
use PhpOption\Some;
use PlainCommands\Annotations;
use PlainCommands\Reflection\MethodDefinition;
use PlainCommands\Reflection\ParameterDefinition;
use Stringy\StaticStringy;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Colada\x;
use function Functional\map;
use function Functional\each;

class Command
{
    /**
     * @var CommandSet
     */
    private $container;

    /**
     * @var CommandOption[]
     */
    private $globalOptions = [];

    /**
     * @var MethodDefinition
     */
    private $definition;

    /**
     * @var string
     */
    private $name;

    /**
     * @var Option<Annotations\Command>
     */
    private $annotation;

    /**
     * @var SymfonyCommand
     */
    private $target;

    /**
     * Array of handlers, ordered strictly to the method's parameters
     *
     * @var InputHandler[]
     */
    private $parameters = [];

    /**
     * "Silent" version of constructor (optional return value instead of an exception)
     *
     * @param CommandSet       $container
     * @param MethodDefinition $definition
     *
     * @return Option<self>
     */
    public static function create(CommandSet $container, MethodDefinition $definition)
    {
        try {
            return new Some(new static($container, $definition));
        } catch (InvalidArgumentException $exception) {
            return None::create();
        }
    }

    /**
     * @throws InvalidArgumentException If the method is not marked as a command
     *
     * @param CommandSet       $container
     * @param MethodDefinition $definition
     */
    private function __construct(CommandSet $container, MethodDefinition $definition)
    {
        $this->container = $container;
        $this->definition = $definition;
        $this->annotation = $definition->readAnnotation(Annotations\Command::class);

        $this->defineName();

        $this->target = $this->configure(new SymfonyCommand($this->getFullName()));
    }

    /**
     * @param CommandOption[] $commandSetOptions
     *
     * @return $this
     */
    public function setGlobalOptions(array $commandSetOptions)
    {
        $this->globalOptions = $commandSetOptions;

        return $this;
    }

    public function getTarget(): SymfonyCommand
    {
        return $this->target;
    }

    public function isEqualTo(Command $command): bool
    {
        return $this->getFullName() === $command->getFullName();
    }

    private function configure(SymfonyCommand $target): SymfonyCommand
    {
        /*
         * Arguments, options
         */
        $this->buildParameters($target);

        $target
            ->setAliases($this->getShortcuts())
            ->setDescription($this->definition->getShortDescription())
            ->setHelp($this->definition->getLongDescription())
            // TODO ->addUsage() from @example PHPDoc tags
        ;

        return $target->setCode($this);
    }

    /**
     * @see SymfonyCommand::execute()
     * @see SymfonyCommand::setCode()
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    public function __invoke(InputInterface $input, OutputInterface $output)
    {
        each($this->globalOptions, x()->execute($input, $output));

        $arguments = map($this->parameters, x()->execute($input, $output));

        // Use return value from the command for the exit code (as in usual Symfony commands)
        return $this->definition->invokeFor($this->container->getObject(), $arguments);
    }

    public function getShortcuts(): array
    {
        return $this->annotation->map(x()->shortcuts)->getOrElse([]);
    }

    /**
     * Define name from an annotation, or from a method name.
     *
     * @throws InvalidArgumentException If name can not be extracted.
     */
    private function defineName()
    {
        $this->name = $this->annotation
            ->map(function ($annotation) {
                return $annotation->value ?: (string) StaticStringy::dasherize($this->definition->getName());
            })
            // Let's stick with annotations for now. Skip this feature.
//            ->orElse(Option::fromReturn(function () {
//                if (str($this->method->getName())->endsWith("Command")) {
//                    return (string) str($this->method->getName())->removeRight("Command")->dasherize();
//                }
//            }))
            ->getOrThrow(
                new InvalidArgumentException("Method {$this->definition->getName()}() is not a command.")
            )
        ;
    }

    /**
     * Command name with the namespace (from the command set), like "doctrine:create"
     *
     * @return string
     */
    public function getFullName(): string
    {
        return implode(':', array_filter([$this->container->getNamespace(), $this->name]));
    }

    private function buildParameters(SymfonyCommand $target)
    {
        /** @var ParameterDefinition $parameter */
        foreach ($this->definition->getParameters() as $parameter) {
            $runtimeArgument = RuntimeArgument::create($target, $parameter);
            $booleanOption = ParameterOption::create($target, $parameter);
            $argument = Argument::create($target, $parameter);

            $this->parameters[] = $runtimeArgument->orElse($booleanOption)->orElse($argument)->getOrThrow(
                // TODO Add FQCN, like \PlainCommands\Examples\RepositoryGrabber::loadFromGitHub()
                new InvalidArgumentException("Parameter \${$parameter->getName()} cannot be processed")
            );
        }
    }
}
