<?php

/**
 * @copyright Frederic G. Østby
 * @license   http://www.makoframework.com/license
 */

namespace mako\afterburner;

use mako\application\Application as BaseApplication;
use mako\application\CurrentApplication;
use mako\application\web\Application;
use mako\error\ErrorHandler;
use mako\http\exceptions\HttpException;
use Throwable;

use function array_diff;
use function frankenphp_handle_request;
use function gc_collect_cycles;

/**
 * FrankenPHP afterburner.
 */
class FrankenPHP implements AfterburnerInterface
{
	/**
	 * {@inheritDoc}
	 */
	public static function run(Application $application, mixed ...$options): void
	{
		$classesToKeep = $application->getContainer()->getInstanceClassNames();

		$requests = 0;
		$maxRequests = $options['maxRequests'] ?? 1000;

		// Handle requests.

		do {
			// Clone the application so that we have a clean slate for each request.

			$currentApplication = clone $application;

			$currentApplication->getContainer()->replaceInstance(BaseApplication::class, $currentApplication);

			CurrentApplication::set($currentApplication);

			// Handle the request.

			$success = frankenphp_handle_request(static function () use ($currentApplication) {
				try {
					$currentApplication->run();
				}
				catch (Throwable $e) {
					$currentApplication->getContainer()->get(ErrorHandler::class)->handler($e, shouldExit: false);

					if (($e instanceof HttpException) === false) {
						return false;
					}
				}
			});

			// Reset the container to the default state and collect garbage.

			$classesToRemove = array_diff($application->getContainer()->getInstanceClassNames(), $classesToKeep);

			foreach ($classesToRemove as $class) {
				$application->getContainer()->removeInstance($class);
			}

			gc_collect_cycles();

		} while ($success && ++$requests < $maxRequests);
	}
}
