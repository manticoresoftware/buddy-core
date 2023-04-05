<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\Plugin;

use Composer\Autoload\ClassLoader;
use Composer\Console\Application;
use Exception;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class Pluggable {
	const PLUGIN_PREFIX = 'buddy-plugin-';

	/** @var ?ClassLoader */
	protected ?ClassLoader $autoloader = null;

	/** @var string */
	protected string $pluginDir;

	/**
	 * Initialize and set plugin dir
	 * @param Settings $settings
	 * @return void
	 */
	public function __construct(public Settings $settings) {
		$this->setPluginDir(
			$this->findPluginDir()
		);
	}

	/**
	 * Install specified package in plugin dir by using composer call
	 * @param string $package
	 * @return void
	 * @throws Exception
	 */
	public function install(string $package): void {
		$this->composer(
			'require', [
				'--prefer-source' => false,
				'--prefer-dist' => true,
				'--prefer-install' => true,
				'--prefer-stable' => true,
				'--optimize-autoloader' => true,
				'--no-plugins' => true,
				'--no-scripts' => true,
			], $package
		);
	}

	/**
	 * Remove installed package
	 * @param string $package
	 * @return void
	 */
	public function remove(string $package): void {
		$this->composer(
			'remove', [], $package
		);
	}

	/**
	 * Get list of all packages that are installed in a plugin directory
	 * @return array<array{full:string,short:string,version:string}>
	 * @throws Exception
	 */
	public function getList(): array {
		$pluginPrefixLen = strlen(static::PLUGIN_PREFIX);
		$composerFile = $this->getPluginComposerFile();
		$composerContent = file_get_contents($composerFile);
		if ($composerContent === false) {
			throw new Exception('Unable to read composer file');
		}
		/** @var array{require?:array<string,string>} $composerJson */
		$composerJson = json_decode($composerContent, true);
		if (!isset($composerJson['require'])) {
			return [];
		}

		$reduceFn = static function ($carry, $v) use ($composerJson, $pluginPrefixLen) {
			$pos = strpos($v, static::PLUGIN_PREFIX, strpos($v, '/') ?: 0);
			if ($pos !== false) {
				$carry[] = [
					'full' => $v,
					'short' => substr($v, $pos + $pluginPrefixLen),
					'version' => $composerJson['require'][$v],
				];
			}
			return $carry;
		};

		return array_reduce(
			array_keys($composerJson['require']),
			$reduceFn,
			[]
		);
	}

	/**
	 * This is helper function to get fully qualified class name for plugin
	 * by using fully qualified composer name of it
	 * @param string $name
	 * @return string
	 */
	public function getClassNamespaceByFullName(string $name): string {
		$composerFile = $this->pluginDir
			. DIRECTORY_SEPARATOR
			. 'vendor'
			. DIRECTORY_SEPARATOR
			. $name
			. DIRECTORY_SEPARATOR
			. 'composer.json';

		if (!is_file($composerFile)) {
			throw new Exception("Failed to find composer.json file for plugin: $name");
		}
		$composerContent = file_get_contents($composerFile);
		if (!$composerContent) {
			throw new Exception("Failed to get contents of composer.json for plugin: $name");
		}

		$composerJson = json_decode($composerContent, true);
		if (!$composerJson) {
			throw new Exception("Failed to decode contents of composer.json file for plugin: $name");
		}

		/** @var array{autoload:array{"psr-4"?:array<string,string>}} $composerJson */
		$psr4 = $composerJson['autoload']['psr-4'] ?? false;
		if (!$psr4) {
			throw new Exception("Failed to detect psr4 autoload section in composer.json file for plugin: $name");
		}
		return array_key_first($psr4);
	}

	/**
	 * Just little helper to update autoload we should call it outside of thread
	 * @return void
	 * @throws Exception
	 */
	public function reload(): void {
		$this->unregisterAutoload();
		$this->registerAutoload();
	}

	/**
	 * Remove old composer autoloader just to register new one
	 * @return void
	 */
	public function unregisterAutoload(): void {
		if (!isset($this->autoloader)) {
			return;
		}
		spl_autoload_unregister([$this->autoloader, 'loadClass']);
		$this->autoloader->unregister();
	}

	/**
	 * Register new autoloader from the composer generated autoload file
	 * @return void
	 * @throws Exception
	 */
	public function registerAutoload(): void {
		$autoloadFile = $this->getAutoloadFile();
		if (!file_exists($autoloadFile)) {
			return;
		}

		$this->autoloader = include $autoloadFile;
	}

	/**
	 * Execute low level composer command through Application class
	 * @param string $command
	 * @param array<string,string|bool> $options
	 * @param null|string $package
	 * @return BufferedOutput
	 * @throws Exception
	 */
	protected function composer(string $command, array $options, ?string $package = null): BufferedOutput {
		$pluginDir = $this->getPluginDir();
		$cwdDir = getcwd() ?: __DIR__;
		// Change to the directory where the composer.json file is located
		chdir($pluginDir);

		// Instantiate the Composer Application
		$app = new Application();
		$app->setAutoExit(false);
		// $app->setCatchExceptions(true);

		// Build the input for the install command
		$args = array_merge(
			[
				'command' => $command,
			], $options
		);
		if ($package) {
			$args['packages'] = [$package];
		}
		$input = new ArrayInput($args);

		// Run the install command
		$output = new BufferedOutput();
		$resultCode = $app->run($input, $output);
		chdir($cwdDir);

		if ($resultCode !== 0) {
			// In case of error just print output in the debug mode
			Buddy::debug($output->fetch());
			throw new Exception(
				"Failed to install '$package'. Got exit code: $resultCode. "
				. 'Please, use debug mode and check logs.'
			);
		}

		return $output;
	}

	/**
	 * Set plugin dir, default we initialize from settings
	 * @param string $dir
	 * @return static
	 */
	public function setPluginDir(string $dir): static {
		$this->pluginDir = $dir;
		return $this;
	}

	/**
	 * Get current plugin dir
	 * @return string
	 */
	public function getPluginDir(): string {
		return $this->pluginDir;
	}

	/**
	 * Get path to the plugin dir where we install all of it
	 * @return string
	 * @throws Exception
	 */
	protected function findPluginDir(): string {
		$pluginDir = $this->settings->commonPluginDir;
		if (!$pluginDir) {
			throw new Exception('Failed to detect plugin dir to use');
		}
		$pluginDir = rtrim($pluginDir, DIRECTORY_SEPARATOR)
			. DIRECTORY_SEPARATOR
			. 'buddy-plugins'
		;

		if (!is_dir($pluginDir)) {
			mkdir($pluginDir, 0755, true);
		}

		return $pluginDir;
	}

	/**
	 * Get path to the composer.json of the plugins
	 * @return string
	 * @throws Exception
	 */
	protected function getPluginComposerFile(): string {
		$pluginDir = $this->getPluginDir();
		$composerFile = $pluginDir. DIRECTORY_SEPARATOR. 'composer.json';
		if (!file_exists($composerFile)) {
			file_put_contents($composerFile, '{"minimum-stability":"dev"}');
		}

		return $composerFile;
	}

	/**
	 * Get autoload file that generated by composer in plugin dir where
	 * we have all vendor packages installed with CREATE PLUGIN command
	 * @return string
	 * @throws Exception
	 */
	protected function getAutoloadFile(): string {
		$pluginDir = $this->getPluginDir();
		return $pluginDir
			. DIRECTORY_SEPARATOR
			. 'vendor'
			. DIRECTORY_SEPARATOR
			. 'autoload.php';
	}
}
