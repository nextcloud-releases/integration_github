<?php
/**
 * @copyright Copyright (c) 2022 Julien Veyssier <eneiluj@posteo.net>
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Github\Reference;

use OC\Collaboration\Reference\LinkReferenceProvider;
use OCA\Github\AppInfo\Application;
use OCA\Github\Service\GithubAPIService;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\IReferenceProvider;

class GithubReferenceProvider implements IReferenceProvider {
	private LinkReferenceProvider $linkReferenceProvider;
	private GithubAPIService $githubAPIService;
	private ?string $userId;

	public function __construct(LinkReferenceProvider $linkReferenceProvider,
								GithubAPIService $githubAPIService,
								?string $userId) {
		$this->linkReferenceProvider = $linkReferenceProvider;
		$this->githubAPIService = $githubAPIService;
		$this->userId = $userId;
	}

	/**
	 * @inheritDoc
	 */
	public function matchReference(string $referenceText): bool {
		if (preg_match('/^(?:https?:\/\/)?(?:www\.)?github\.com\/[^\/\?]+\/[^\/\?]+\/(issues|pull)\/[0-9]+/', $referenceText) !== false
		) {
			return true;
		}
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function resolveReference(string $referenceText): ?IReference {
		if ($this->matchReference($referenceText)) {
			$reference = $this->linkReferenceProvider->resolveReference($referenceText);
			$issuePath = $this->getIssuePath($referenceText);
			if ($issuePath !== null) {
				[$owner, $repo, $id] = $issuePath;
				$issueInfo = $this->githubAPIService->getIssueInfo($this->userId, $owner, $repo, $id);
				$reference->setRichObject(Application::APP_ID, [
					'github_type' => 'issue',
					'github_issue_id' => $id,
					'github_repo_owner' => $owner,
					'github_repo' => $repo,
					...$issueInfo,
				]);
			} else {
				$prPath = $this->getPrPath($referenceText);
				if ($prPath !== null) {
					[$owner, $repo, $id] = $prPath;
					$prInfo = $this->githubAPIService->getPrInfo($this->userId, $owner, $repo, $id);
					$reference->setRichObject(Application::APP_ID, [
						'github_type' => 'pr',
						'github_pr_id' => $id,
						'github_repo_owner' => $owner,
						'github_repo' => $repo,
						...$prInfo,
					]);
				}
			}
			return $reference;
		}

		return null;
	}

	private function getIssuePath(string $url): ?array {
		preg_match('/^(?:https?:\/\/)?(?:www\.)?github\.com\/([^\/\?]+)\/([^\/\?]+)\/issues\/([0-9]+)/', $url, $matches);
		return count($matches) === 4 ? [$matches[1], $matches[2], $matches[3]] : null;
	}

	private function getPrPath(string $url): ?array {
		preg_match('/^(?:https?:\/\/)?(?:www\.)?github\.com\/([^\/\?]+)\/([^\/\?]+)\/pull\/([0-9]+)/', $url, $matches);
		return count($matches) === 4 ? [$matches[1], $matches[2], $matches[3]] : null;
	}

	public function getCachePrefix(string $referenceId): string {
		return $referenceId;
	}

	public function getCacheKey(string $referenceId): ?string {
		return null;
	}
}
