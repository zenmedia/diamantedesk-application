<?php
/*
 * Copyright (c) 2014 Eltrino LLC (http://eltrino.com)
 *
 * Licensed under the Open Software License (OSL 3.0).
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://opensource.org/licenses/osl-3.0.php
 *
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@eltrino.com so we can send you a copy immediately.
 */
namespace Diamante\DistributionBundle\Command;

use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Oro\Bundle\InstallerBundle\Command\InstallCommand as OroInstallCommand;
use Oro\Bundle\InstallerBundle\CommandExecutor;
use Oro\Bundle\InstallerBundle\Command\Provider\InputOptionProvider;
use Symfony\Component\Console\Input\InputOption;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;

class InstallCommand extends OroInstallCommand
{
    /**
     * @var CommandExecutor
     */
    protected $commandExecutor;

    /**
     * @var Logger
     *
     */
    protected $logger;

    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this->setName('diamante:install')
            ->setDescription('Install Diamante Desk Bundles')
            ->addOption('application-url', null, InputOption::VALUE_OPTIONAL, 'Application URL')
            ->addOption('organization-name', null, InputOption::VALUE_OPTIONAL, 'Organization name')
            ->addOption('user-name', null, InputOption::VALUE_OPTIONAL, 'User name')
            ->addOption('user-email', null, InputOption::VALUE_OPTIONAL, 'User email')
            ->addOption('user-firstname', null, InputOption::VALUE_OPTIONAL, 'User first name')
            ->addOption('user-lastname', null, InputOption::VALUE_OPTIONAL, 'User last name')
            ->addOption('user-password', null, InputOption::VALUE_OPTIONAL, 'User password')
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Timeout for child command execution',
                CommandExecutor::DEFAULT_TIMEOUT
            );
    }

    /**
     * Executes installation of all Diamante bundles
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @return null|integer null or 0 if everything went fine, or an error code
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->logger = $this->getContainer()->get('monolog.logger.diamante');

        $this->logger
            ->info(sprintf('DiamanteDesk installation started at %s', date('Y-m-d H:i:s')));
        try {
            $this->checkStep($output);
            $this->oroInit($input, $output);
            $this->oroInstall($input, $output);
            $this->runExistingCommand('cache:clear');
            $this->runExistingCommand('diamante:desk:install');
            $this->runExistingCommand('diamante:user:install');
            $this->runExistingCommand('diamante:embeddedform:install');
            $this->runExistingCommand('assets:install');
            $this->runExistingCommand('assetic:dump', array('--process-isolation' => true));
            $this->oroAdministrationSetup($output);
        } catch (\Exception $e) {
            $this->logger
                ->error(sprintf('Installation failed with error: %s', $e->getMessage()));
            $output->writeln($e->getMessage());
            return 255;
        }
        $this->logger
            ->info(sprintf('DiamanteDesk installation finished at %s', date('Y-m-d H:i:s')));
        return 0;
    }

    protected function oroInit(InputInterface $input, OutputInterface $output)
    {
        $this->inputOptionProvider = new InputOptionProvider($output, $input, $this->getHelperSet()->get('dialog'));

        $this->commandExecutor = new CommandExecutor(
            $input->hasOption('env') ? $input->getOption('env') : null,
            $output,
            $this->getApplication(),
            $this->getContainer()->get('oro_cache.oro_data_cache_manager')
        );
        $this->commandExecutor->setDefaultTimeout($input->hasOption('timeout') ? $input->getOption('timeout') : 0);
    }

    protected function oroInstall(InputInterface $input, OutputInterface $output)
    {
        $this->prepareStep($this->commandExecutor)
            ->loadDataStep($this->commandExecutor, $output)
            ->finalStep($this->commandExecutor, $output, $input);
    }

    /**
     * @param CommandExecutor $commandExecutor
     * @param OutputInterface $output
     * @param InputInterface $input
     * @return InstallCommand
     */
    protected function finalStep(CommandExecutor $commandExecutor, OutputInterface $output, InputInterface $input)
    {
        $output->writeln('<info>Preparing application.</info>');

        $assetsOptions = array(
            '--exclude' => array('OroInstallerBundle')
        );
        if ($input->hasOption('symlink') && $input->getOption('symlink')) {
            $assetsOptions['--symlink'] = true;
        }

        $commandExecutor
            ->runCommand(
                'oro:navigation:init',
                array(
                    '--process-isolation' => true,
                )
            )
            ->runCommand(
                'fos:js-routing:dump',
                array(
                    '--target'            => 'web/js/routes.js',
                    '--process-isolation' => true,
                )
            )
            ->runCommand('oro:localization:dump')
            ->runCommand(
                'oro:translation:dump',
                array(
                    '--process-isolation' => true,
                )
            )
            ->runCommand(
                'oro:requirejs:build',
                array(
                    '--ignore-errors'     => true,
                    '--process-isolation' => true,
                )
            );

        // run installer scripts
        $this->processInstallerScripts($output, $commandExecutor);

        $this->updateInstalledFlag(date('c'));

        $output->writeln('');

        return $this;
    }

    /**
     * @param CommandExecutor $commandExecutor
     * @param OutputInterface $output
     *
     * @return InstallCommand
     */
    protected function loadDataStep(CommandExecutor $commandExecutor, OutputInterface $output)
    {
        $output->writeln('<info>Setting up database.</info>');

        $commandExecutor
            ->runCommand(
                'oro:migration:load',
                [
                    '--force'             => true,
                    '--process-isolation' => true,
                    '--timeout'           => $commandExecutor->getDefaultTimeout(),
                    '--exclude'           => array('DiamanteEmbeddedFormBundle', 'DiamanteDeskBundle')
                ]
            )
            ->runCommand(
                'oro:workflow:definitions:load',
                [
                    '--process-isolation' => true,
                ]
            )
            ->runCommand(
                'oro:process:configuration:load',
                [
                    '--process-isolation' => true
                ]
            )
            ->runCommand(
                'oro:migration:data:load',
                [
                    '--process-isolation' => true,
                    '--no-interaction'    => true,
                ]
            );

        $output->writeln('');

        return $this;
    }

    protected function oroAdministrationSetup(OutputInterface $output)
    {
        $output->writeln('<info>Administration setup.</info>');

        $this->updateSystemSettings();
        $this->updateOrganization($this->commandExecutor);
        $this->updateUser($this->commandExecutor);
    }

    /**
     * Update the organization
     *
     * @param CommandExecutor $commandExecutor
     */
    protected function updateOrganization(CommandExecutor $commandExecutor)
    {
        /** @var ConfigManager $configManager */
        $configManager             = $this->getContainer()->get('oro_config.global');
        $defaultOrganizationName   = $configManager->get('diamante_distribution.organization_name');
        $organizationNameValidator = function ($value) use (&$defaultOrganizationName) {
            $len = strlen(trim($value));
            if ($len === 0 && empty($defaultOrganizationName)) {
                throw new \Exception('The organization name must not be empty');
            }
            if ($len > 15) {
                throw new \Exception('The organization name must be not more than 15 characters long');
            }
            return $value;
        };

        $options = [
            'organization-name' => [
                'label'                  => 'Organization name',
                'askMethod'              => 'askAndValidate',
                'additionalAskArguments' => [$organizationNameValidator],
                'defaultValue'           => $defaultOrganizationName,
            ]
        ];

        $commandParameters = [];
        foreach ($options as $optionName => $optionData) {
            $commandParameters['--' . $optionName] = $this->inputOptionProvider->get(
                $optionName,
                $optionData['label'],
                $optionData['defaultValue'],
                $optionData['askMethod'],
                $optionData['additionalAskArguments']
            );
        }

        $commandExecutor->runCommand(
            'oro:organization:update',
            array_merge(
                [
                    'organization-name' => 'default',
                    '--process-isolation' => true,
                ],
                $commandParameters
            )
        );
    }

    /**
     * Update system settings such as app url, company name and short name
     */
    protected function updateSystemSettings()
    {
        /** @var ConfigManager $configManager */
        $configManager = $this->getContainer()->get('oro_config.global');
        $options       = [
            'application-url' => [
                'label'                  => 'Application URL',
                'config_key'             => 'diamante_distribution.application_url',
                'askMethod'              => 'ask',
                'additionalAskArguments' => [],
            ]
        ];

        foreach ($options as $optionName => $optionData) {
            $configKey    = $optionData['config_key'];
            $defaultValue = $configManager->get($configKey);

            $value = $this->inputOptionProvider->get(
                $optionName,
                $optionData['label'],
                $defaultValue,
                $optionData['askMethod'],
                $optionData['additionalAskArguments']
            );

            // update setting if it's not empty and not equal to default value
            if (!empty($value) && $value !== $defaultValue) {
                $configManager->set($configKey, $value);
            }
        }

        $configManager->flush();
    }

    /**
     * @param OutputInterface $output
     *
     * @return InstallCommand
     * @throws \RuntimeException
     */
    protected function checkStep(OutputInterface $output)
    {
        $output->writeln('<info>Requirements check:</info>');

        if (!class_exists('OroRequirements')) {
            require_once $this->getContainer()->getParameter('kernel.root_dir')
                . DIRECTORY_SEPARATOR
                . 'OroRequirements.php';
        }

        if (!class_exists('DiamanteDeskRequirements')) {
            require_once $this->getContainer()->getParameter('kernel.root_dir')
                . DIRECTORY_SEPARATOR
                . 'DiamanteDeskRequirements.php';
        }

        $collection = new \OroRequirements();
        $diamanteDeskCollection = new \DiamanteDeskRequirements();

        $this->renderTable($collection->getMandatoryRequirements(), 'Mandatory requirements', $output);
        $this->renderTable($collection->getPhpIniRequirements(), 'PHP settings', $output);
        $this->renderTable($collection->getOroRequirements(), 'Oro specific requirements', $output);
        $this->renderTable(
            $diamanteDeskCollection->getDiamanteDeskRequirements(),
            'DiamanteDesk requirements',
            $output
        );
        $this->renderTable($collection->getRecommendations(), 'Optional recommendations', $output);

        if (count($collection->getFailedRequirements())) {
            throw new \RuntimeException(
                'Some system requirements are not fulfilled. Please check output messages and fix them.'
            );
        }

        $output->writeln('');

        return $this;
    }

    /**
     * Run existing command in system
     * @param string $commandName
     * @param array $parameters
     */
    protected function runExistingCommand($commandName, array $parameters = array())
    {
        try {
            $this->commandExecutor
                ->runCommand(
                    $commandName,
                    $parameters
                );
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error occured during execution of %s: %s', $commandName, $e->getMessage()));
        }
    }
}
