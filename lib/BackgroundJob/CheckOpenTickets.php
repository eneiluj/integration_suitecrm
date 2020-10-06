<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Julien Veyssier <eneiluj@posteo.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\SuiteCRM\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCA\SuiteCRM\SuiteCRMAPIService;
use Psr\Log\LoggerInterface;

/**
 * Class CheckOpenTickets
 *
 * @package OCA\SuiteCRM\BackgroundJob
 */
class CheckOpenTickets extends TimedJob {

	/** @var SuiteCRMAPIService */
	protected $suitecrmAPIService;

	/** @var LoggerInterface */
	protected $logger;

	public function __construct(SuiteCRMAPIService $suitecrmAPIService,
								LoggerInterface $logger) {
		// Every 15 minutes
		$this->setInterval(60 * 15);

		$this->suitecrmAPIService = $suitecrmAPIService;
		$this->logger = $logger;
	}

	protected function run($argument): void {
		$this->suitecrmAPIService->checkOpenTickets();
		$this->logger->info('Checked if users have open SuiteCRM tickets.');
	}
}
