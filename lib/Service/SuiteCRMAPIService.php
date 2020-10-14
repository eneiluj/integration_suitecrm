<?php
/**
 * Nextcloud - suitecrm
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\SuiteCRM\Service;

use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUser;
use OCP\Http\Client\IClientService;
use OCP\Notification\IManager as INotificationManager;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

use OCA\SuiteCRM\AppInfo\Application;

class SuiteCRMAPIService {

	private $l10n;
	private $logger;

	/**
	 * Service to make requests to SuiteCRM v3 (JSON) API
	 */
	public function __construct (IUserManager $userManager,
								string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								INotificationManager $notificationManager,
								IClientService $clientService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->config = $config;
		$this->userManager = $userManager;
		$this->clientService = $clientService;
		$this->notificationManager = $notificationManager;
		$this->client = $clientService->newClient();
	}

	/**
	 * triggered by a cron job
	 * notifies user of their number of new tickets
	 *
	 * @return void
	 */
	public function checkOpenTickets(): void {
		$this->userManager->callForAllUsers(function (IUser $user) {
			$this->checkOpenTicketsForUser($user->getUID());
		});
	}

	/**
	 * @param string $userId
	 * @return void
	 */
	private function checkOpenTicketsForUser(string $userId): void {
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token', '');
		if ($accessToken) {
			$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token', '');
			$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
			$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');
			$suitecrmUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url', '');
			if ($clientID && $clientSecret && $suitecrmUrl) {
				$lastNotificationCheck = $this->config->getUserValue($userId, Application::APP_ID, 'last_open_check', '');
				$lastNotificationCheck = $lastNotificationCheck === '' ? null : $lastNotificationCheck;
				// get the suitecrm user ID
				$me = $this->request(
					$suitecrmUrl, $accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'users/me'
				);
				if (isset($me['id'])) {
					$my_user_id = $me['id'];

					$notifications = $this->getNotifications(
						$suitecrmUrl, $accessToken, $refreshToken, $clientID, $clientSecret, $userId, $lastNotificationCheck
					);
					if (!isset($notifications['error']) && count($notifications) > 0) {
						$lastNotificationCheck = $notifications[0]['updated_at'];
						$this->config->setUserValue($userId, Application::APP_ID, 'last_open_check', $lastNotificationCheck);
						$nbOpen = 0;
						foreach ($notifications as $n) {
							$user_id = $n['user_id'];
							$state_id = $n['state_id'];
							$owner_id = $n['owner_id'];
							// if ($state_id === 1) {
							if ($owner_id === $my_user_id && $state_id === 1) {
								$nbOpen++;
							}
						}
						error_log('NB OPEN for '.$me['lastname'].': '.$nbOpen);
						if ($nbOpen > 0) {
							$this->sendNCNotification($userId, 'new_open_tickets', [
								'nbOpen' => $nbOpen,
								'link' => $suitecrmUrl
							]);
						}
					}
				}
			}
		}
	}

	/**
	 * @param string $userId
	 * @param string $subject
	 * @param string $params
	 * @return void
	 */
	private function sendNCNotification(string $userId, string $subject, array $params): void {
		$manager = $this->notificationManager;
		$notification = $manager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('dum', 'dum')
			->setSubject($subject, $params);

		$manager->notify($notification);
	}

	/**
	 * @param string $url
	 * @param string $accessToken
	 * @param string $userId
	 * @param ?string $since
	 * @param ?int $limit
	 * @return array
	 */
	public function getNotifications(string $url, string $accessToken, string $userId,
									?string $since = null, ?int $limit = null): array {
		return $this->getAlerts($url, $accessToken, $userId, $since);
	}

	/**
	 * get user alerts that are
	 * - in the future
	 * - not already read
	 * - after since (if defined)
	 *
	 * @param string $url
	 * @param string $accessToken
	 * @param string $userId
	 * @param ?string $since
	 * @param ?int $limit
	 * @return array
	 */
	public function getAlerts(string $url, string $accessToken, string $userId, ?string $since = null, ?int $limit = null): array {
		$scrmUserId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id', '');
		$params = [];
		$filters = [
			urlencode('filter[assigned_user_id][eq]') . '=' . urlencode($scrmUserId),
			urlencode('filter[is_read][eq]') . '=0',
		];
		$result = $this->request(
			$url, $accessToken, $userId, 'module/Alerts?' . implode('&', $filters)
		);
		if (isset($result['error'])) {
			return $result;
		}
		// get target date for calls and meetings
		$tsNow = (new \DateTime())->getTimestamp();
		$futureAlerts = [];
		foreach ($result['data'] as $alert) {
			$urlRedirect = $alert['attributes']['url_redirect'];
			$isCall = preg_match('/module=Calls/', $urlRedirect);
			$isMeeting = preg_match('/module=Meetings/', $urlRedirect);
			$recordMatch = [];
			preg_match('/record=([a-z0-9\-]+)/', $urlRedirect, $recordMatch);
			if (($isCall || $isMeeting) && count($recordMatch) > 1) {
				$recordId = $recordMatch[1];
				$filters = [
					urlencode('filter[id][eq]') . '=' . urlencode($recordId),
				];
				$module = $isCall ? 'Calls' : 'Meetings';
				$elems = $this->request(
					$url, $accessToken, $userId, 'module/' . $module . '?' . implode('&', $filters)
				);
				if (!isset($elems['error']) && isset($elems['data']) && count($elems['data']) > 0
					&& isset($elems['data'][0]['attributes']['date_start'])
				) {
					$tsElem = (new \DateTime($elems['data'][0]['attributes']['date_start']))->getTimestamp();
					if ($tsElem > $tsNow) {
						$alert['date_start'] = $elems['data'][0]['attributes']['date_start'];
						$alert['type'] = $isCall ? 'call' : 'meeting';
						$futureAlerts[] = $alert;
					}
				}
			}
		}
		// filter results by date
		if (!is_null($since)) {
			$sinceDate = new \DateTime($since);
			$sinceTimestamp = $sinceDate->getTimestamp();
			$futureAlerts = array_filter($futureAlerts, function($elem) use ($sinceTimestamp) {
				$date = new \DateTime($elem['date_start']);
				$ts = $date->getTimestamp();
				return $ts > $sinceTimestamp;
			});
		}
		// sort by date
		$a = usort($futureAlerts, function($a, $b) {
			$a = new \Datetime($a['date_start']);
			$ta = $a->getTimestamp();
			$b = new \Datetime($b['date_start']);
			$tb = $b->getTimestamp();
			return ($ta < $tb) ? -1 : 1;
		});
		if ($limit) {
			$futureAlerts = array_slice($futureAlerts, 0, $limit);
		}
		$futureAlerts = array_values($futureAlerts);

		return $futureAlerts;
	}

	/**
	 * @param string $url
	 * @param string $accessToken
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param string $userId
	 * @param string $query
	 * @return array
	 */
	public function search(string $url, string $accessToken,
							string $refreshToken, string $clientID, string $clientSecret, string $userId,
							string $query): array {
		$params = [
			'query' => $query,
			'limit' => 20,
		];
		$searchResult = $this->request(
			$url, $accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'tickets/search', $params
		);

		$result = [];
		if (isset($searchResult['assets']) && isset($searchResult['assets']['Ticket'])) {
			foreach ($searchResult['assets']['Ticket'] as $id => $t) {
				array_push($result, $t);
			}
		}
		// get ticket state names
		$states = $this->request(
			$url, $accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'ticket_states'
		);
		$statesById = [];
		if (!isset($states['error'])) {
			foreach ($states as $state) {
				$id = $state['id'];
				$name = $state['name'];
				if ($id && $name) {
					$statesById[$id] = $name;
				}
			}
		}
		foreach ($result as $k => $v) {
			if (array_key_exists($v['state_id'], $statesById)) {
				$result[$k]['state_name'] = $statesById[$v['state_id']];
			}
		}
		// get ticket priority names
		$prios = $this->request(
			$url, $accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'ticket_priorities'
		);
		$priosById = [];
		if (!isset($prios['error'])) {
			foreach ($prios as $prio) {
				$id = $prio['id'];
				$name = $prio['name'];
				if ($id && $name) {
					$priosById[$id] = $name;
				}
			}
		}
		foreach ($result as $k => $v) {
			if (array_key_exists($v['priority_id'], $priosById)) {
				$result[$k]['priority_name'] = $priosById[$v['priority_id']];
			}
		}
		// add owner information
		$userIds = [];
		$field = 'customer_id';
		foreach ($result as $k => $v) {
			if (!in_array($v[$field], $userIds)) {
				array_push($userIds, $v[$field]);
			}
		}
		$userDetails = [];
		foreach ($userIds as $uid) {
			$user = $this->request(
				$url, $accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'users/' . $uid
			);
			if (!isset($user['error'])) {
				$userDetails[$uid] = [
					'firstname' => $user['firstname'],
					'lastname' => $user['lastname'],
					'organization_id' => $user['organization_id'],
					'image' => $user['image'],
				];
			}
		}
		foreach ($result as $k => $v) {
			if (array_key_exists($v[$field], $userDetails)) {
				$user = $userDetails[$v[$field]];
				$result[$k]['u_firstname'] = $user['firstname'];
				$result[$k]['u_lastname'] = $user['lastname'];
				$result[$k]['u_organization_id'] = $user['organization_id'];
				$result[$k]['u_image'] = $user['image'];
			}
		}
		return $result;
	}

	/**
	 * authenticated request to get an image from suitecrm
	 *
	 * @param string $url
	 * @param string $accessToken
	 * @param string $suiteUserId
	 * @return string
	 */
	public function getSuiteCRMAvatar(string $url,
									string $accessToken,
									string $suiteUserId): string {
		$url = $url . '/index.php?entryPoint=download&id=' . urlencode($suiteUserId) . '_photo&type=Users';
		$options = [
			'headers' => [
				'Authorization'  => 'Bearer ' . $accessToken,
				'User-Agent' => 'Nextcloud SuiteCRM integration',
			]
		];
		return $this->client->get($url, $options)->getBody();
	}

	/**
	 * @param string $suitecrmUrl
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @return array
	 */
	public function request(string $suitecrmUrl, string $accessToken, string $userId,
							string $endPoint, array $params = [], string $method = 'GET'): array {
		try {
			$url = $suitecrmUrl . '/Api/index.php/V8/' . $endPoint;
			$options = [
				'headers' => [
					'Authorization'  => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud SuiteCRM integration',
				]
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					// manage array parameters
					$paramsContent = '';
					foreach ($params as $key => $value) {
						if (is_array($value)) {
							foreach ($value as $oneArrayValue) {
								$paramsContent .= $key . '[]=' . urlencode($oneArrayValue) . '&';
							}
							unset($params[$key]);
						}
					}
					$paramsContent .= http_build_query($params);
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
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return json_decode($body, true);
			}
		} catch (ServerException | ClientException $e) {
			$response = $e->getResponse();
			$body = (string) $response->getBody();
			// try to refresh token if it's invalid
			if ($response->getStatusCode() === 401) {
				$this->logger->info('Trying to REFRESH the access token', ['app' => $this->appName]);
				$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token', '');
				$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
				$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');
				// try to refresh the token
				$result = $this->requestOAuthAccessToken($suitecrmUrl, [
					'client_id' => $clientID,
					'client_secret' => $clientSecret,
					'grant_type' => 'refresh_token',
					'refresh_token' => $refreshToken,
				], 'POST');
				if (isset($result['access_token'], $result['refresh_token'])) {
					$accessToken = $result['access_token'];
					$this->config->setUserValue($userId, Application::APP_ID, 'token', $accessToken);
					$refreshToken = $result['refresh_token'];
					$this->config->setUserValue($userId, Application::APP_ID, 'refresh_token', $refreshToken);
					// retry the request with new access token
					return $this->request(
						$suitecrmUrl, $accessToken, $userId, $endPoint, $params, $method
					);
				}
			}
			$this->logger->warning('SuiteCRM API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $url
	 * @param array $params
	 * @param string $method
	 * @return array
	 */
	public function requestOAuthAccessToken(string $url, array $params = [], string $method = 'GET'): array {
		try {
			$url = $url . '/Api/access_token';
			$options = [
				'headers' => [
					'User-Agent'  => 'Nextcloud SuiteCRM integration',
				]
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
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('OAuth access token refused')];
			} else {
				return json_decode($body, true);
			}
		} catch (\Exception $e) {
			$this->logger->warning('SuiteCRM OAuth error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}
}
