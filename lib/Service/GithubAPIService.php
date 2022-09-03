<?php
/**
 * Nextcloud - github
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Github\Service;

use DateInterval;
use DateTime;
use Exception;
use OCA\Github\AppInfo\Application;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCP\Http\Client\IClientService;
use Throwable;

class GithubAPIService {
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IL10N
	 */
	private $l10n;
	/**
	 * @var \OCP\Http\Client\IClient
	 */
	private $client;
	/**
	 * @var IConfig
	 */
	private $config;

	/**
	 * Service to make requests to GitHub v3 (JSON) API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								IClientService $clientService) {
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->client = $clientService->newClient();
		$this->config = $config;
	}

	/**
	 * Request an avatar image
	 * @param string $userId
	 * @param string $githubUserName
	 * @return ?string Avatar image data
	 * @throws Exception
	 */
	public function getAvatar(string $userId, string $githubUserName): ?string {
		$userInfo = $this->request($userId, 'users/' . $githubUserName);
		if (!isset($userInfo['error']) && isset($userInfo['avatar_url'])) {
			return $this->client->get($userInfo['avatar_url'])->getBody();
		}
		return null;
	}

	/**
	 * Actually get notifications
	 * @param string $userId
	 * @param ?string $since optional date to filter notifications
	 * @param ?bool $participating optional param to only show notifications the user is participating to
	 * @return array notification list or error
	 */
	public function getNotifications(string $userId, ?string $since = null, ?bool $participating = null): array {
		$params = [];
		if (is_null($since)) {
			$twoWeeksEarlier = new DateTime();
			$twoWeeksEarlier->sub(new DateInterval('P14D'));
			$params['since'] = $twoWeeksEarlier->format('Y-m-d\TH:i:s\Z');
		} else {
			$params['since'] = $since;
		}
		if (!is_null($participating)) {
			$params['participating'] = $participating ? 'true' : 'false';
		}
		return $this->request($userId, 'notifications', $params);
	}

	/**
	 * Unsubscribe a notification, does the same as in GitHub notifications page
	 * @param string $userId
	 * @param int $id Notification id
	 * @return array request result
	 */
	public function unsubscribeNotification(string $userId, int $id): array {
		$params = [
			'ignored' => true
		];
		return $this->request($userId, 'notifications/threads/' . $id . '/subscription', $params, 'PUT');
	}

	/**
	 * Mark a notification as read
	 * @param string $userId
	 * @param int $id Notification id
	 * @return array request result
	 */
	public function markNotificationAsRead(string $userId, int $id): array {
		return $this->request($userId, 'notifications/threads/' . $id, [], 'POST');
	}

	/**
	 * Search repositories
	 * @param string $userId
	 * @param string $query What to search for
	 * @param int $offset
	 * @param int $limit
	 * @return array request result
	 */
	public function searchRepositories(string $userId, string $query, int $offset = 0, int $limit = 5): array {
		$params = [
			'q' => $query,
			'order' => 'desc'
		];
		$result = $this->request($userId, 'search/repositories', $params, 'GET');
		if (!isset($result['error'])) {
			$result['items'] = array_slice($result['items'], $offset, $limit);
		}
		return $result;
	}

	/**
	 * Search issues and PRs
	 * @param string $userId
	 * @param string $query What to search for
	 * @param int $offset
	 * @param int $limit
	 * @return array request result
	 */
	public function searchIssues(string $userId, string $query, int $offset = 0, int $limit = 5): array {
		$params = [
			'q' => $query,
			'order' => 'desc'
		];
		$result = $this->request($userId, 'search/issues', $params, 'GET');
		if (!isset($result['error'])) {
			$result['items'] = array_slice($result['items'], $offset, $limit);
			$reposToGet = [];
			foreach ($result['items'] as $k => $entry) {
				$repoFullName = str_replace('https://api.github.com/repos/', '', $entry['repository_url']);
				$spl = explode('/', $repoFullName);
				$owner = $spl[0];
				$repo = $spl[1];
				$number = $entry['number'];
				if (isset($entry['pull_request'])) {
					$info = $this->request($userId, 'repos/' . $owner . '/' . $repo . '/' . 'pulls/' . $number);
					if (!isset($info['error'])) {
						$result['items'][$k]['merged'] = $info['merged'];
						if (isset($info['head'], $info['head']['repo'], $info['head']['repo']['owner'], $info['head']['repo']['owner']['login'], $info['head']['repo']['owner']['avatar_url'])) {
							$result['items'][$k]['project_avatar_url'] = $info['head']['repo']['owner']['avatar_url'];
							$result['items'][$k]['project_owner_login'] = $info['head']['repo']['owner']['login'];
						}
					}
				} else {
					$reposToGet[] = [
						'key' => $repoFullName,
						'owner' => $owner,
						'repo' => $repo,
					];
				}
			}
			// get repos info (for issues only)
			$repoAvatarUrls = [];
			$repoOwnerLogins = [];
			foreach ($reposToGet as $repo) {
				$info = $this->request($userId, 'repos/' . $repo['owner'] . '/' . $repo['repo']);
				if (!isset($info['error']) && isset($info['owner'], $info['owner']['avatar_url'], $info['owner']['login'])) {
					$repoAvatarUrls[$repo['key']] = $info['owner']['avatar_url'];
					$repoOwnerLogins[$repo['key']] = $info['owner']['login'];
				}
			}
			foreach ($result['items'] as $k => $entry) {
				$repoFullName = str_replace('https://api.github.com/repos/', '', $entry['repository_url']);
				if (!isset($entry['pull_request'])
					&& array_key_exists($repoFullName, $repoAvatarUrls)
					&& array_key_exists($repoFullName, $repoOwnerLogins)
				) {
					$result['items'][$k]['project_avatar_url'] = $repoAvatarUrls[$repoFullName];
					$result['items'][$k]['project_owner_login'] = $repoOwnerLogins[$repoFullName];
				}
			}
		}
		return $result;
	}

	/**
	 * @param string $userId
	 * @param string $githubUserName
	 * @return array
	 */
	public function getUserInfo(string $userId, string $githubUserName): array {
		return $this->request($userId, 'users/' . $githubUserName);
	}

	/**
	 * @param string $userId
	 * @param string $owner
	 * @param string $repo
	 * @param int $issueNumber
	 * @return array
	 */
	public function getIssueInfo(string $userId, string $owner, string $repo, int $issueNumber): array {
		$endpoint = 'repos/' . $owner . '/' . $repo . '/issues/' . $issueNumber;
		return $this->request($userId, $endpoint);
	}

	/**
	 * @param string $userId
	 * @param string $owner
	 * @param string $repo
	 * @param int $commentId
	 * @return array
	 */
	public function getIssueCommentInfo(string $userId, string $owner, string $repo, int $commentId): array {
		$endpoint = 'repos/' . $owner . '/' . $repo . '/issues/comments/' . $commentId;
		return $this->request($userId, $endpoint);
	}

	/**
	 * @param string $userId
	 * @param string $owner
	 * @param string $repo
	 * @param int $prNumber
	 * @return array
	 */
	public function getPrInfo(string $userId, string $owner, string $repo, int $prNumber): array {
		$endpoint = 'repos/' . $owner . '/' . $repo . '/pulls/' . $prNumber;
		return $this->request($userId, $endpoint);
	}

	/**
	 * Make an authenticated HTTP request to GitHub API
	 * @param string $userId
	 * @param string $endPoint The path to reach in api.github.com
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @return array decoded request result or error
	 */
	public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET'): array {
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		try {
			$url = 'https://api.github.com/' . $endPoint;
			$options = [
				'headers' => [
					'Authorization' => 'token ' . $accessToken,
					'User-Agent' => 'Nextcloud GitHub integration',
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = json_encode($params);
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return json_decode($body, true) ?: [];
			}
		} catch (Exception | Throwable $e) {
			$this->logger->warning('GitHub API error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}

	public function revokeOauthToken(string $userId): array {
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$clientId = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$endPoint = 'applications/' . $clientId . '/token';
		try {
			$url = 'https://api.github.com/' . $endPoint;
			$options = [
				'headers' => [
					'User-Agent' => 'Nextcloud GitHub integration',
					'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
				],
				'body' => json_encode([
					'access_token' => $accessToken,
				]),
			];

			$response = $this->client->delete($url, $options);
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return [];
			}
		} catch (Exception $e) {
			$this->logger->warning('GitHub API error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Make the request to get an OAuth token
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @return array parsed result or error
	 */
	public function requestOAuthAccessToken(array $params = [], string $method = 'GET'): array {
		try {
			$url = 'https://github.com/login/oauth/access_token';
			$options = [
				'headers' => [
					'User-Agent' => 'Nextcloud GitHub integration'
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('OAuth access token refused')];
			} else {
				parse_str($body, $resultArray);
				return $resultArray;
			}
		} catch (Exception $e) {
			$this->logger->warning('GitHub OAuth error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}
}
