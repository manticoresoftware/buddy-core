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
	 *
	 * @param string $package
	 * @return void
	 * @throws Exception
	 */
	public function install(string $package): void {
		$this->composer(
			'require', [
				'--prefer-dist' => true,
				'--prefer-install' => true,
				'--prefer-stable' => true,
				'--optimize-autoloader' => true,
			], $package
		);

		$this->unregisterAutoload();
		$this->registerAutoload();
	}

	/**
	 * @return array<string>
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

		$reduceFn = static function ($carry, $v) use ($pluginPrefixLen) {
			$pos = strpos($v, static::PLUGIN_PREFIX, strpos($v, '/') ?: 0);
			if ($pos !== false) {
				$carry[] = substr($v, $pos + $pluginPrefixLen);
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
	 *
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
	 *
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
			throw new Exception("Failed to install '$package'. Got exit code: $resultCode. Please, check log.");
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
		echo 0;
		$pluginDir = $this->settings->commonPluginDir;
		if (!$pluginDir) {
			throw new Exception('Failed to detect plugin dir to use');
		}
		echo 1;
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
	 *
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
