<?php
/**
 * Nextcloud - SuiteCRM
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

return [
    'routes' => [
        ['name' => 'config#oauthRedirect', 'url' => '/oauth-redirect', 'verb' => 'GET'],
        ['name' => 'config#oauthConnect', 'url' => '/oauth-connect', 'verb' => 'GET'],
        ['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
        ['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
        ['name' => 'suitecrmAPI#getNotifications', 'url' => '/notifications', 'verb' => 'GET'],
        ['name' => 'suitecrmAPI#getSuiteCRMUrl', 'url' => '/url', 'verb' => 'GET'],
        ['name' => 'suitecrmAPI#getSuiteCRMAvatar', 'url' => '/avatar', 'verb' => 'GET'],
    ]
];
