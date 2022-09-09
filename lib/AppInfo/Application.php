<?php
/**
 * Nextcloud - Github
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Github\AppInfo;

use OCA\Github\Listener\GithubReferenceListener;
use OCA\Github\Listener\ReferenceLoadAdditionalScriptsListener;
use OCA\Github\Reference\GithubReferenceProvider;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\Collaboration\Resources\LoadAdditionalScriptsEvent;
use OCP\IConfig;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;

use OCA\Github\Dashboard\GithubWidget;
use OCA\Github\Search\GithubSearchReposProvider;
use OCA\Github\Search\GithubSearchIssuesProvider;

/**
 * Class Application
 *
 * @package OCA\Github\AppInfo
 */
class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_github';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();
		$this->container = $container;
		$this->config = $container->query(IConfig::class);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(GithubWidget::class);

		$context->registerSearchProvider(GithubSearchIssuesProvider::class);
		$context->registerSearchProvider(GithubSearchReposProvider::class);

		$context->registerReferenceProvider(GithubReferenceProvider::class);
		// TODO ask what does the RenderReferenceEvent event mean
		$context->registerEventListener(RenderReferenceEvent::class, GithubReferenceListener::class);
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, ReferenceLoadAdditionalScriptsListener::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(\Closure::fromCallable([$this, 'registerNavigation']));
	}

	public function registerNavigation(IUserSession $userSession): void {
		$user = $userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
			$container = $this->container;

			if ($this->config->getUserValue($userId, self::APP_ID, 'navigation_enabled', '0') === '1') {
				$container->query(INavigationManager::class)->add(static function () use ($container) {
					$urlGenerator = $container->query(IURLGenerator::class);
					$l10n = $container->query(IL10N::class);
					return [
						'id' => self::APP_ID,

						'order' => 10,

						// the route that will be shown on startup
						'href' => 'https://github.com',

						// the icon that will be shown in the navigation
						// this file needs to exist in img/
						'icon' => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),

						// the title of your application. This will be used in the
						// navigation or on the settings page of your app
						'name' => $l10n->t('GitHub'),
					];
				});
			}
		}
	}
}

