<?php

namespace Drupal\Console;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Debug\Debug;
use Drupal\Console\Helper\HelperTrait;
use Drupal\Console\Helper\DrupalHelper;
use Drupal\Console\Style\DrupalStyle;

/**
 * Class Application
 * @package Drupal\Console\Console
 */
class Application extends BaseApplication
{
    use HelperTrait;

    /**
     * @var string
     */
    const NAME = 'Drupal Console';
    /**
     * @var string
     */
    const VERSION = '0.10.9';
    /**
     * @var string
     */
    const DRUPAL_SUPPORTED_VERSION = '8.0.3';
    /**
     * @var \Drupal\Console\Config
     */
    protected $config;
    /**
     * @var string
     */
    protected $directoryRoot;
    /**
     * @var string
     * The Drupal environment.
     */
    protected $env;
    /**
     * @var \Drupal\Console\Helper\TranslatorHelper
     */
    protected $translator;

    /**
     * @var string
     */
    protected $commandName;

    /**
     * @var string
     */
    protected $errorMessage;

    /**
     * Create a new application.
     *
     * @param $config
     * @param $translator
     */
    public function __construct($config, $translator)
    {
        $this->config = $config;
        $this->translator = $translator;
        $this->env = $config->get('application.environment');

        parent::__construct($this::NAME, $this::VERSION);

        $this->getDefinition()->addOption(
            new InputOption('--env', '-e', InputOption::VALUE_OPTIONAL, $this->trans('application.console.arguments.env'), $this->env)
        );
        $this->getDefinition()->addOption(
            new InputOption('--root', null, InputOption::VALUE_OPTIONAL, $this->trans('application.console.arguments.root'))
        );
        $this->getDefinition()->addOption(
            new InputOption('--no-debug', null, InputOption::VALUE_NONE, $this->trans('application.console.arguments.no-debug'))
        );
        $this->getDefinition()->addOption(
            new InputOption('--learning', null, InputOption::VALUE_NONE, $this->trans('application.console.arguments.learning'))
        );
        $this->getDefinition()->addOption(
            new InputOption('--generate-chain', '-c', InputOption::VALUE_NONE, $this->trans('application.console.arguments.generate-chain'))
        );
        $this->getDefinition()->addOption(
            new InputOption('--generate-inline', '-i', InputOption::VALUE_NONE, $this->trans('application.console.arguments.generate-inline'))
        );
        $this->getDefinition()->addOption(
            new InputOption('--generate-doc', '-d', InputOption::VALUE_NONE, $this->trans('application.console.arguments.generate-doc'))
        );
        $this->getDefinition()->addOption(
            new InputOption('--target', '-t', InputOption::VALUE_OPTIONAL, $this->trans('application.console.arguments.target'))
        );
        $this->getDefinition()->addOption(
            new InputOption('--uri', '-l', InputOption::VALUE_REQUIRED, $this->trans('application.console.arguments.uri'))
        );
        $this->getDefinition()->addOption(
            new InputOption('--yes', '-y', InputOption::VALUE_NONE, $this->trans('application.console.arguments.yes'))
        );

        $options = $config->get('application.default.global.options')?:[];
        foreach ($options as $key => $option) {
            if ($this->getDefinition()->hasOption($key)) {
                $_SERVER['argv'][] = sprintf('--%s', $key);
            }
        }


        if (count($_SERVER['argv'])>1 && stripos($_SERVER['argv'][1], '@')===0) {
            $_SERVER['argv'][1] = sprintf(
                '--target=%s',
                substr($_SERVER['argv'][1], 1)
            );
        }
    }

    /**
     * Gets the default input definition.
     *
     * @return InputDefinition An InputDefinition instance
     */
    protected function getDefaultInputDefinition()
    {
        return new InputDefinition(
            [
                new InputArgument('command', InputArgument::REQUIRED, $this->trans('application.console.input.definition.command')),
                new InputOption('--help', '-h', InputOption::VALUE_NONE, $this->trans('application.console.input.definition.help')),
                new InputOption('--quiet', '-q', InputOption::VALUE_NONE, $this->trans('application.console.input.definition.quiet')),
                new InputOption('--verbose', '-v|vv|vvv', InputOption::VALUE_NONE, $this->trans('application.console.input.definition.verbose')),
                new InputOption('--version', '-V', InputOption::VALUE_NONE, $this->trans('application.console.input.definition.version')),
                new InputOption('--ansi', '', InputOption::VALUE_NONE, $this->trans('application.console.input.definition.ansi')),
                new InputOption('--no-ansi', '', InputOption::VALUE_NONE, $this->trans('application.console.input.definition.no-ansi')),
                new InputOption('--no-interaction', '-n', InputOption::VALUE_NONE, $this->trans('application.console.input.definition.no-interaction')),
            ]
        );
    }

    /**
     * Returns the long version of the application.
     *
     * @return string The long application version
     *
     * @api
     */
    public function getLongVersion()
    {
        if ('UNKNOWN' !== $this->getName() && 'UNKNOWN' !== $this->getVersion()) {
            return sprintf($this->trans('application.console.options.version'), $this->getName(), $this->getVersion());
        }

        return '<info>Drupal Console</info>';
    }

    /**
     * {@inheritdoc}
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $output = new DrupalStyle($input, $output);
        $root = null;
        $config = $this->getConfig();
        $target = $input->getParameterOption(['--target'], null);
        $commandName = null;

        if ($input) {
            $commandName = $this->getCommandName($input);
            $this->commandName = $commandName;
        }

        $targetConfig = [];
        if ($target && $config->loadTarget($target)) {
            $targetConfig = $config->getTarget($target);
            $root = $targetConfig['root'];
        }

        if ($targetConfig && $targetConfig['remote']) {
            $remoteResult = $this->getRemoteHelper()->executeCommand(
                $commandName,
                $target,
                $targetConfig,
                $input->__toString(),
                $config->getUserHomeDir()
            );
            $output->writeln($remoteResult);
            return 0;
        }

        if (!$target) {
            $root = $input->getParameterOption(['--root'], null);
            $root = (strpos($root, '/')===0)?$root:sprintf('%s/%s', getcwd(), $root);
        }

        $uri = $input->getParameterOption(['--uri', '-l']);
        $env = $input->getParameterOption(['--env', '-e'], getenv('DRUPAL_ENV') ?: 'prod');

        if (!$env) {
            $this->env = $env;
        }

        $debug = getenv('DRUPAL_DEBUG') !== '0'
          && !$input->hasParameterOption(['--no-debug', ''])
          && $env !== 'prod';

        if ($debug) {
            Debug::enable();
        }

        $drupal = $this->getDrupalHelper();
        $this->getCommandDiscoveryHelper()->setApplicationRoot($this->getDirectoryRoot());
        $recursive = false;

        if (!$root) {
            $root = getcwd();
            $recursive = true;
        }

        if (!$drupal->isValidRoot($root, $recursive)) {
            $commands = $this->getCommandDiscoveryHelper()->getConsoleCommands();
            if (!$commandName) {
                $this->errorMessage = $this->trans('application.site.errors.directory');
            }
            $this->registerCommands($commands);
        } else {
            $this->getKernelHelper()->setRequestUri($uri);
            $this->getKernelHelper()->setDebug($debug);
            $this->getKernelHelper()->setEnvironment($this->env);

            $this->prepare($drupal);
        }

        if ($commandName && $this->has($commandName)) {
            $command = $this->get($commandName);
            $parameterOptions = $this->getDefinition()->getOptions();
            foreach ($parameterOptions as $optionName => $parameterOption) {
                $parameterOption = [
                    sprintf('--%s', $parameterOption->getName()),
                    sprintf('-%s', $parameterOption->getShortcut())
                ];
                if (true === $input->hasParameterOption($parameterOption)) {
                    $option = $this->getDefinition()->getOption($optionName);
                    $command->getDefinition()->addOption($option);
                }
            }
        }

        return parent::doRun($input, $output);
    }

    /**
     * Prepare drupal.
     *
     * @param DrupalHelper $drupal
     */
    public function prepare(DrupalHelper $drupal)
    {
        chdir($drupal->getRoot());
        $this->getSite()->setSiteRoot($drupal->getRoot());

        if ($drupal->isValidInstance()) {
            $this->bootDrupal($drupal);
        }

        if ($drupal->isInstalled()) {
            $disabledModules = $this->config->get('application.disable.modules');
            $this->getCommandDiscoveryHelper()->setDisabledModules($disabledModules);
            $commands = $this->getCommandDiscoveryHelper()->getCommands();
        } else {
            $commands = $this->getCommandDiscoveryHelper()->getConsoleCommands();
            $this->errorMessage = $this->trans('application.site.errors.settings');
        }

        $this->registerCommands($commands);
    }

    /**
     * @param $commands
     */
    private function registerCommands($commands)
    {
        if (!$commands) {
            return;
        }

        foreach ($commands as $command) {
            $aliases = $this->getCommandAliases($command);
            if ($aliases) {
                $command->setAliases($aliases);
            }

            $this->add($command);
        }

        $autoWireForcedCommands = $this->getConfig()->get(
            sprintf(
                'application.autowire.commands.forced'
            )
        );

        foreach ($autoWireForcedCommands as $autoWireForcedCommand) {
            $command = new $autoWireForcedCommand['class'](
                $autoWireForcedCommand['helperset']?$this->getHelperSet():null
            );
            $this->add($command);
        }

        $autoWireNameCommand = $this->getConfig()->get(
            sprintf(
                'application.autowire.commands.name.%s',
                $this->commandName
            )
        );

        if ($autoWireNameCommand) {
            $command = new $autoWireNameCommand['class'](
                $autoWireNameCommand['helperset']?$this->getHelperSet():null
            );
            $this->add($command);
        }
    }

    /**
     * @param $command
     * @return array|null
     */
    private function getCommandAliases($command)
    {
        $aliasKey = sprintf(
            'application.default.commands.%s.aliases',
            str_replace(':', '.', $command->getName())
        );

        return $this->config->get($aliasKey);
    }

    /**
     * @param DrupalHelper $drupal
     */
    public function bootDrupal(DrupalHelper $drupal)
    {
        $this->getKernelHelper()->setClassLoader($drupal->getAutoLoadClass());
        $drupal->setInstalled($this->getKernelHelper()->bootKernel());
    }

    /**
     * @return \Drupal\Console\Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param mixed $config
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * @return string
     */
    public function getDirectoryRoot()
    {
        return $this->directoryRoot;
    }

    /**
     * @param string $directoryRoot
     */
    public function setDirectoryRoot($directoryRoot)
    {
        $this->directoryRoot = $directoryRoot;
    }

    /**
     * @param array $helpers
     */
    public function addHelpers(array $helpers)
    {
        $defaultHelperSet = $this->getHelperSet();
        foreach ($helpers as $alias => $helper) {
            $defaultHelperSet->set($helper, is_int($alias) ? null : $alias);
        }
    }

    /**
     * Remove dispatcher.
     */
    public function removeDispatcher()
    {
        $dispatcher = new EventDispatcher();
        $this->setDispatcher($dispatcher);
    }

    /**
     * @param $key string
     *
     * @return string
     */
    public function trans($key)
    {
        return $this->translator->trans($key);
    }

    /**
     * @return string
     */
    public function getErrorMessage()
    {
        return $this->errorMessage;
    }
}
