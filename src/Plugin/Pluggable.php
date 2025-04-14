<?php declare(strict_types=1);

/*
 Copyright (c) 2024-present, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\Plugin;

use Composer\Console\Application;
use Exception;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\Strings;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @phpstan-type PluginItem array{full:string,short:string,version:string,version?:int}
 */
final class Pluggable {
	const PLUGIN_PREFIX = 'buddy-plugin-';
	const CORE_NS_PREFIX = 'Manticoresearch\\Buddy\\Base\\Plugin\\';
	const EXTRA_NS_PREFIX = 'Manticoresearch\\Buddy\\Plugin\\';

	/**
	 * Version of the pluggable system
	 * Increment this number when making breaking changes to the pluggable interfaces
	 */
	const VERSION = 1;

	/** @var array<mixed> */
	protected array $autoloadMap = [];

	/** @var array<array{full:string,short:string,version:string,version?:int}> */
	protected static array $corePlugins;

	/** @var array<array{full:string,short:string,version:string,version?:int}> */
	protected static array $localPlugins;

	/** @var array<array{full:string,short:string,version:string,version?:int}> */
	protected static array $extraPlugins;

	/** @var array<string,true> Here we keep disabled plugins map */
	protected static array $disabledPlugins = [];

	/** @var string */
	protected string $pluginDir;

	/** @var ?ContainerInterface $container */
	protected static ?ContainerInterface $container = null;

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
	 * Set core plugins
	 * @param array<string> $plugins
	 * @return void
	 */
	public static function setCorePlugins(array $plugins): void {
		static::$corePlugins = [];
		$version = Buddy::getVersion();
		foreach ($plugins as $fullName) {
			$item = [
				'full' => $fullName,
				'short' => static::getShortName($fullName),
				'version' => $version,
			];

			// Try to get the required version from the plugin
			$pluginPrefix = static::CORE_NS_PREFIX . ucfirst(Strings::camelcaseBySeparator($item['short'], '-'));
			$pluginPayloadClass = "$pluginPrefix\\Payload";
			if (class_exists($pluginPayloadClass) && method_exists($pluginPayloadClass, 'getRequiredVersion')) {
				$item['pluggable_version'] = $pluginPayloadClass::getRequiredVersion();
			}

			static::$corePlugins[] = $item;
		}
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	public static function isCorePlugin(string $name): bool {
		return !!array_filter(static::$corePlugins, fn ($plugin) => $plugin['full'] === $name);
	}

	/**
	 * The cacheable method for getting core plugins
	 * @return array<array{full:string,short:string,version:string,version?:int}>
	 */
	public function getCorePlugins(): array {
		return static::$corePlugins;
	}

	/**
	 * The cacheable method for getting local plugins
	 * @param bool $refresh
	 * @return array<array{full:string,short:string,version:string,version?:int}>
	 */
	public function getLocalPlugins(bool $refresh = false): array {
		if (!isset(static::$localPlugins) || $refresh) {
			static::$localPlugins = $this->fetchLocalPlugins();
		}
		return static::$localPlugins;
	}

	/**
	 * The cacheable method for getting extra plugins
	 * @param bool $refresh
	 * @return array<array{full:string,short:string,version:string,version?:int}>
	 */
	public function getExtraPlugins(bool $refresh = false): array {
		if (!isset(static::$extraPlugins) || $refresh) {
			static::$extraPlugins = $this->fetchExtraPlugins();
		}
		return static::$extraPlugins;
	}

	/**
	 * @return array<string,true>
	 */
	public static function getDisabledPlugins(): array {
		return static::$disabledPlugins;
	}

	/**
	 * Enable plugin by name
	 * @param string $name
	 * @return bool
	 */
	public function enablePlugin(string $name): bool {
		if (!isset(static::$disabledPlugins[$name])) {
			return false;
		}

		unset(static::$disabledPlugins[$name]);
		return true;
	}

	/**
	 * Disable plugin by name
	 * @param string $name
	 * @return bool
	 */
	public function disablePlugin(string $name): bool {
		if (isset(static::$disabledPlugins[$name])) {
			return false;
		}

		static::$disabledPlugins[$name] = true;
		return true;
	}

	/**
	 * Set current container interface for future usage
	 * @param ContainerInterface $container
	 * @return void
	 */
	public static function setContainer(ContainerInterface $container): void {
		static::$container = $container;
	}

	/**
	 * Get current container
	 * @return ContainerInterface
	 */
	public static function getContainer(): ContainerInterface {
		return static::$container ?? new ContainerBuilder();
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
	 * @return array<array{full:string,short:string,version:string,version?:int}>
	 * @throws Exception
	 */
	public function getList(): array {
		$composerContent = $this->readComposerContent();
		if ($composerContent === false) {
			return [];
		}

		/** @var array{require?:array<string,string>} $composerJson */
		$composerJson = simdjson_decode($composerContent, true);
		if (!isset($composerJson['require'])) {
			return [];
		}

		return array_reduce(
			array_keys($composerJson['require']),
			function ($carry, $v) use ($composerJson) {
				return $this->processPluginEntry($carry, $v, $composerJson);
			},
			[]
		);
	}

	/**
	 * Process a single plugin entry from composer.json
	 * @param array<array{full:string,short:string,version:string,version?:int}> $carry Accumulated result
	 * @param string $v Package name
	 * @param array{require:array<string,string>} $composerJson
	 * @return array<array{full:string,short:string,version:string,version?:int}> Updated accumulated result
	 */
	private function processPluginEntry(array $carry, string $v, array $composerJson): array {
		$pluginPrefixLen = strlen(static::PLUGIN_PREFIX);
		$pos = strpos($v, static::PLUGIN_PREFIX, strpos($v, '/') ?: 0);
		if ($pos !== false) {
			$item = [
				'full' => $v,
				'short' => substr($v, $pos + $pluginPrefixLen),
				'version' => $composerJson['require'][$v],
			];

			// Try to get the required version from the plugin
			$pluginPrefix = static::EXTRA_NS_PREFIX . ucfirst(Strings::camelcaseBySeparator($item['short'], '-'));
			$pluginPayloadClass = "$pluginPrefix\Payload";
			if (class_exists($pluginPayloadClass) && method_exists($pluginPayloadClass, 'getRequiredVersion')) {
				$item['pluggable_version'] = $pluginPayloadClass::getRequiredVersion();

				$requiredVersion = static::VERSION;
				if ($item['pluggable_version'] < $requiredVersion) {
					Buddy::warning(
						"Plugin {$v} has version {$item['pluggable_version']}, "
							. 'but current pluggable version is ' . static::VERSION . '. Plugin will be skipped.'
					);
					// Skip this plugin from activating
					return $carry;
				}
			}

			$carry[] = $item;
		}
		return $carry;
	}

	/**
	 * Read the composer.json content from the plugin directory
	 * @return string|false The file content or false on failure
	 */
	private function readComposerContent() {
		$composerFile = $this->getPluginComposerFile();
		$composerContent = file_exists($composerFile) && is_writable($this->getPluginDir())
		? file_get_contents($composerFile)
		: false;

		if ($composerContent === false) {
			$message = "Pluggable system is disabled. Unable to read composer file from plugin dir: $composerFile, ";
			$message .= 'make sure that you have correct permissions on plugin dir';
			Buddy::warning($message);
		}

		return $composerContent;
	}

	/**
	 * This is helper function to get fully qualified class name for plugin
	 * by using fully qualified composer name of it
	 * @param string $name
	 * @return string
	 */
	public function getClassNamespaceByFullName(string $name): string {
		// It's simple in case it's core plugin
		if (static::isCorePlugin($name)) {
			$baseName = static::getShortName($name);
			$ns = str_replace(' ', '', ucwords(str_replace('-', ' ', $baseName)));
			return "Manticoresearch\\Buddy\\Base\\Plugin\\$ns\\";
		}

		// For external plugin, get it f
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

		$composerJson = simdjson_decode($composerContent, true);
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
		if (!$this->autoloadMap) {
			return;
		}
		spl_autoload_unregister(static::autoloader(...));
		$this->autoloadMap = [];
	}

	/**
	 * Register new autoloader from the composer generated autoload file
	 * @return void
	 * @throws Exception
	 */
	public function registerAutoload(): void {
		$autoloadFile = $this->getPSR4AutoloadFile();
		if (!file_exists($autoloadFile)) {
			return;
		}

		// Get the registered autoload functions
		$autoloadFunctions = spl_autoload_functions();
		$psr4Map = include $autoloadFile;

		$nsMap = [];
		$nsFile = $this->getNSAutoloadFile();
		if (file_exists($nsFile)) {
			$nsMap = include $nsFile;
		}
		foreach (array_merge($psr4Map, $nsMap) as $namespace => $dirs) {
			// Loop through the functions and inspect the registered namespaces
			$namespaceFound = false;
			foreach ($autoloadFunctions as $function) {
				if (!is_array($function) || !($function[0] instanceof \Composer\Autoload\ClassLoader)) {
					continue;
				}

				$registeredNamespaces = $function[0]->getPrefixesPsr4();

				// Check if your namespace is registered
				if (array_key_exists($namespace, $registeredNamespaces)) {
					$namespaceFound = true;
					break;
				}
			}

			// Add to map only if missing
			if ($namespaceFound) {
				continue;
			}

			$this->autoloadMap[$namespace] = $dirs;
		}

		spl_autoload_register(static::autoloader(...));
	}


	/**
	 * Implement own autoloader that will allow us to skip installed packages
	 * @param string $className
	 * @return void
	 */
	public function autoloader(string $className): void {
		foreach ($this->autoloadMap as $namespacePrefix => [$directory]) {
			$prefixLength = strlen($namespacePrefix);
			if (strncmp($className, $namespacePrefix, $prefixLength) !== 0) {
				continue;
			}


			// This works for psr4
			$relativeClassName = substr($className, $prefixLength);
			$file = $directory . DIRECTORY_SEPARATOR
				. str_replace('\\', DIRECTORY_SEPARATOR, $relativeClassName) . '.php';
			if (file_exists($file)) {
				include_once $file;
				return;
			}

			// And this is for namespaces
			$file = $directory . DIRECTORY_SEPARATOR
				. str_replace('\\', DIRECTORY_SEPARATOR, $className) . '.php';
			if (file_exists($file)) {
				include_once $file;
				return;
			}
		}
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
	 * Helper to fetch local plugins
	 * @return array<array{full:string,short:string,version:string,version?:int}>
	 * @throws Exception
	 */
	public function fetchLocalPlugins(): array {
		$localPluginDir = realpath(__DIR__ . '/../../../../../plugins');
		if (false === $localPluginDir) {
			return [];
		}

		$plugins = [];
		$items = scandir($localPluginDir);
		if (false === $items) {
			throw new Exception('Failed to readh local plugin dir');
		}
		foreach ($items as $item) {
			if (!is_dir("$localPluginDir/$item") || $item === '.' || $item === '..') {
				continue;
			}

			$shortName = str_replace(static::PLUGIN_PREFIX, '', $item);
			$composerFile = "$localPluginDir/$item/composer.json";
			$composerContent = file_get_contents($composerFile);
			if (!is_string($composerContent)) {
				throw new Exception("Failed to read composer file for plugin: $shortName");
			}
			/** @var array{name:?string} */
			$composer = simdjson_decode($composerContent, true);
			if (!isset($composer['name'])) {
				throw new Exception("Failed to detect local plugin name from file: $composerFile");
			}

			$item = [
				'full' => $composer['name'],
				'short' => $shortName,
				'version' => 'dev-main',
			];

			// Try to get the required version from the plugin
			$pluginPrefix = static::EXTRA_NS_PREFIX . ucfirst(Strings::camelcaseBySeparator($shortName, '-'));
			$pluginPayloadClass = "$pluginPrefix\\Payload";
			if (class_exists($pluginPayloadClass) && method_exists($pluginPayloadClass, 'getRequiredVersion')) {
				$item['pluggable_version'] = $pluginPayloadClass::getRequiredVersion();
			}

			$plugins[] = $item;
		}

		return $plugins;
	}

	/**
	 * Get list of external plugin names
	 * @return array<array{full:string,short:string,version:string,version?:int}>
	 * @throws Exception
	 */
	public function fetchExtraPlugins(): array {
		$this->reload();

		return $this->getList();
	}

	/**
	 * Get the current version of the pluggable system
	 * @return int
	 */
	public static function getVersion(): int {
		return static::VERSION;
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
			try {
				mkdir($pluginDir, 0755, true);
			} catch (\Throwable) {
				Buddy::info(
					$this->settings->commonPluginDir
						. ' is not writable.'
						. ' Ensure the user running searchd has proper permissions to access it.'
				);
			}
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
		if (!file_exists($composerFile) && is_writable($pluginDir)) {
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
	protected function getPSR4AutoloadFile(): string {
		$pluginDir = $this->getPluginDir();
		return $pluginDir
			. DIRECTORY_SEPARATOR
			. 'vendor'
			. DIRECTORY_SEPARATOR
			. 'composer'
			. DIRECTORY_SEPARATOR
			. 'autoload_psr4.php';
	}

	/**
	 * Get autoload file that generated by composer in plugin dir where
	 * we have all vendor packages installed with CREATE PLUGIN command
	 * @return string
	 * @throws Exception
	 */
	protected function getNSAutoloadFile(): string {
		$pluginDir = $this->getPluginDir();
		return $pluginDir
			. DIRECTORY_SEPARATOR
			. 'vendor'
			. DIRECTORY_SEPARATOR
			. 'composer'
			. DIRECTORY_SEPARATOR
			. 'autoload_namespaces.php';
	}

	/**
	 * Helper to get short name from the full qualitifed name of the plugin
	 * @param  string $fullName
	 * @return string
	 */
	protected static function getShortName(string $fullName): string {
		return substr(
			$fullName,
			strpos($fullName, static::PLUGIN_PREFIX)
				+ strlen(static::PLUGIN_PREFIX)
		);
	}

	/**
	 * Check if the plugin is registered in the autoloader
	 * @return bool
	 */
	public function isRegistered(): bool {
		$composerFile = $this->getPluginComposerFile();
		if (!file_exists($composerFile) || !is_writable($this->getPluginDir())) {
			return false;
		}
		return true;
	}

	/**
	 * @param callable $fn
	 * @param array<string> $filter If we pass name we filter only for this plugin
	 * @return void
	 */
	public function iterateProcessors(callable $fn, array $filter = []): void {
		$list = [
			[static::CORE_NS_PREFIX, $this->getCorePlugins()],
			[static::EXTRA_NS_PREFIX, $this->getExtraPlugins()],
			[static::EXTRA_NS_PREFIX, $this->getLocalPlugins()],
		];
		foreach ($list as [$prefix, $plugins]) {
			foreach ($plugins as $plugin) {
				// If we have filter, we
				if ($filter && !in_array($plugin['full'], $filter)) {
					continue;
				}
				$pluginPrefix = $prefix . ucfirst(Strings::camelcaseBySeparator($plugin['short'], '-'));
				$pluginPayloadClass = "$pluginPrefix\\Payload";

				if (!class_exists($pluginPayloadClass)) {
					continue;
				}

				array_map($fn, $pluginPayloadClass::getProcessors());
			}
		}
	}
}
