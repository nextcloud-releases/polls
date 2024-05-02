<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2017 Vinzenz Rosenkranz <vinzenz.rosenkranz@gmail.com>
 *
 * @author René Gieling <github@dartcafe.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Polls\Service;

use OCA\Polls\Db\Poll;
use OCA\Polls\Db\PollMapper;
use OCA\Polls\Db\Preferences;
use OCA\Polls\Db\Share;
use OCA\Polls\Db\UserMapper;
use OCA\Polls\Db\Vote;
use OCA\Polls\Db\VoteMapper;
use OCA\Polls\Event\PollArchivedEvent;
use OCA\Polls\Event\PollCloseEvent;
use OCA\Polls\Event\PollCreatedEvent;
use OCA\Polls\Event\PollDeletedEvent;
use OCA\Polls\Event\PollOwnerChangeEvent;
use OCA\Polls\Event\PollReopenEvent;
use OCA\Polls\Event\PollRestoredEvent;
use OCA\Polls\Event\PollTakeoverEvent;
use OCA\Polls\Event\PollUpdatedEvent;
use OCA\Polls\Exceptions\EmptyTitleException;
use OCA\Polls\Exceptions\ForbiddenException;
use OCA\Polls\Exceptions\InvalidAccessException;
use OCA\Polls\Exceptions\InvalidPollTypeException;
use OCA\Polls\Exceptions\InvalidShowResultsException;
use OCA\Polls\Exceptions\InvalidUsernameException;
use OCA\Polls\Exceptions\UserNotFoundException;
use OCA\Polls\Model\Acl;
use OCA\Polls\Model\Settings\AppSettings;
use OCA\Polls\Model\User\Admin;
use OCA\Polls\Model\User\User;
use OCA\Polls\Model\UserBase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Diagnostics\IQuery;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\Search\ISearchQuery;

class PollService {

	/**
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct(
		private Acl $acl,
		private AppSettings $appSettings,
		private IEventDispatcher $eventDispatcher,
		private Poll $poll,
		private PollMapper $pollMapper,
		private Preferences $preferences,
		private PreferencesService $preferencesService,
		private UserMapper $userMapper,
		private VoteMapper $voteMapper,
		private IDBConnection $dbConnection,
	) {
	}

	/**
	 * Get list of polls including acl and Threshold for "relevant polls"
	 */
	public function list(): array {
		// //
		// HEAVY

		$pollList = [];
		try {
			$userId = $this->userMapper->getCurrentUserCached()->getId();
			$this->preferences = $this->preferencesService->get();

			$qb = $this->dbConnection->getQueryBuilder();
			$expr = $qb->expr();

			$qb->selectAlias('polls_polls.id', 'polls_polls_id')
			   ->selectAlias('polls_polls.type', 'polls_polls_type')
			   ->selectAlias('polls_polls.title', 'polls_polls_title')
				->selectAlias('polls_polls.description', 'polls_polls_description')
				->selectAlias('polls_polls.owner', 'polls_polls_owner')
				->selectAlias('polls_polls.created', 'polls_polls_created')
				->selectAlias('polls_polls.expire', 'polls_polls_expire')
				->selectAlias('polls_polls.deleted', 'polls_polls_deleted')
				->selectAlias('polls_polls.access', 'polls_polls_access')
				->selectAlias('polls_polls.anonymous', 'polls_polls_anonymous')
				->selectAlias('polls_polls.allow_proposals', 'polls_polls_allow_proposals')
				->selectAlias('polls_polls.proposals_expire', 'polls_polls_proposals_expire')
				->selectAlias('polls_polls.vote_limit', 'polls_polls_vote_limit')
				->selectAlias('polls_polls.option_limit', 'polls_polls_option_limit')
				->selectAlias('polls_polls.show_results', 'polls_polls_show_results')
				->selectAlias('polls_polls.admin_access', 'polls_polls_admin_access')
				->selectAlias('polls_polls.allow_maybe', 'polls_polls_allow_maybe')
				->selectAlias('polls_polls.allow_comment', 'polls_polls_allow_comment')
				->selectAlias('polls_polls.hide_booked_up', 'polls_polls_hide_booked_up')
				->selectAlias('polls_polls.use_no', 'polls_polls_use_no')
				->selectAlias('polls_polls.last_interaction', 'polls_polls_last_interaction')
				->selectAlias('polls_polls.misc_settings', 'polls_polls_misc_settings')

				->selectAlias('shares.id', 'shares_id')
				->selectAlias('shares.token', 'shares_token')
				->selectAlias('shares.type', 'shares_type')
				->selectAlias('shares.poll_id', 'shares_poll_id')
				->selectAlias('shares.user_id', 'shares_user_id')
				->selectAlias('shares.display_name', 'shares_display_name')
				->selectAlias('shares.email_address', 'shares_email_address')
				->selectAlias('shares.invitation_sent', 'shares_invitation_sent')
				->selectAlias('shares.locked', 'shares_locked')
				->selectAlias('shares.reminder_sent', 'shares_reminder_sent')
				->selectAlias('shares.misc_settings', 'shares_misc_settings')
				->selectAlias('shares.label', 'shares_label')
				->selectAlias('shares.deleted', 'shares_deleted')

				->selectAlias('user_vote.id', 'user_vote_id')
				->selectAlias('user_vote.poll_id', 'user_vote_poll_id')
				->selectAlias('user_vote.user_id', 'user_vote_user_id')
				->selectAlias('user_vote.vote_option_id', 'user_vote_vote_option_id')
				->selectAlias('user_vote.vote_option_text', 'user_vote_vote_option_text')
				->selectAlias('user_vote.vote_option_hash', 'user_vote_vote_option_hash')
				->selectAlias('user_vote.vote_answer', 'user_vote_vote_answer')
				->selectAlias('user_vote.deleted', 'user_vote_deleted')

				->selectAlias($qb->createFunction('COALESCE(' . $qb->func()->max('options.timestamp') . ', ' . $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT) . ')'),  'max_date')
				->selectAlias($qb->createFunction('COALESCE(' . $qb->func()->min('options.timestamp') . ', ' . $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT) . ')'),  'min_date')
				->selectAlias($qb->func()->count('user_vote.vote_answer'), 'current_user_votes')
				->selectAlias($qb->createFunction('COALESCE(`shares`.`type`, \'\')'),  'user_role')
				->from('polls_polls', 'polls_polls')
				->leftJoin(
					'polls_polls',
					'polls_options',
					'options',
					$expr->eq('polls_polls.id', 'options.poll_id')
				)
				->leftJoin(
					'polls_polls',
					'polls_votes',
					'user_vote',
					$expr->andX(
						$expr->eq('user_vote.poll_id', 'polls_polls.id'),
						$expr->eq('user_vote.user_id', $qb->createNamedParameter($userId))
					)
				)
				->leftJoin(
					'polls_polls',
					'polls_share',
					'shares',
					$expr->andX(
						$expr->eq('polls_polls.id', 'shares.poll_id'),
						$expr->eq('shares.user_id', $qb->createNamedParameter($userId))
					)
				)
				->where(
					$expr->orX(
						$expr->eq('polls_polls.deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
						$expr->eq('polls_polls.owner', $qb->createNamedParameter($userId))
					)
				)
				->groupBy('polls_polls.id');

			$cursor = $qb->executeQuery();
			while ($data = $cursor->fetch()) {
				$poll = new Poll();
				$poll->setId($data['polls_polls_id']);
				$poll->setType($data['polls_polls_type']);
				$poll->setTitle($data['polls_polls_title']);
				$poll->setDescription($data['polls_polls_description']);
				$poll->setOwner($data['polls_polls_owner']);
				$poll->setCreated($data['polls_polls_created']);
				$poll->setExpire($data['polls_polls_expire']);
				$poll->setDeleted($data['polls_polls_deleted']);
				$poll->setAccess($data['polls_polls_access']);
				$poll->setAnonymous($data['polls_polls_anonymous']);
				$poll->setAllowProposals($data['polls_polls_allow_proposals']);
				$poll->setProposalsExpire($data['polls_polls_proposals_expire']);
				$poll->setVoteLimit($data['polls_polls_vote_limit']);
				$poll->setOptionLimit($data['polls_polls_option_limit']);
				$poll->setShowResults($data['polls_polls_show_results']);
				$poll->setAdminAccess($data['polls_polls_admin_access']);
				$poll->setAllowMaybe($data['polls_polls_allow_maybe']);
				$poll->setAllowComment($data['polls_polls_allow_comment']);
				$poll->setHideBookedUp($data['polls_polls_hide_booked_up']);
				$poll->setUseNo($data['polls_polls_use_no']);
				$poll->setLastInteraction($data['polls_polls_last_interaction']);
				$poll->setMiscSettings($data['polls_polls_misc_settings']);
   				$poll->fixUserRole($data['user_role']);

				$share = null;
				if ($data['shares_token'] !== null) {
					$share = new Share();
					$share->setId($data['shares_id']);
					$share->setToken($data['shares_token']);
					$share->setType($data['shares_type']);
					$share->setPollId($data['shares_poll_id']);
					$share->setUserId($data['shares_user_id']);
					$share->setDisplayName($data['shares_display_name']);
					$share->setEmailAddress($data['shares_email_address']);
					$share->setInvitationSent($data['shares_invitation_sent']);
					$share->setLocked($data['shares_locked']);
					$share->setReminderSent($data['shares_reminder_sent']);
					$share->setMiscSettings($data['shares_misc_settings']);
					$share->setLabel($data['shares_label']);
					$share->setDeleted($data['shares_deleted']);
				}

				$vote = null;
				if ($data['user_vote_id'] !== null) {
					$vote = new Vote();
					$vote->setId($data['user_vote_id']);
					$vote->setPollId($data['user_vote_poll_id']);
					$vote->setUserId($data['user_vote_user_id']);
					$vote->setVoteOptionId($data['user_vote_vote_option_id']);
					$vote->setVoteOptionText($data['user_vote_vote_option_text']);
					$vote->setVoteOptionHash($data['user_vote_vote_option_hash']);
					$vote->setVoteAnswer($data['user_vote_vote_answer']);
					$vote->setDeleted($data['user_vote_deleted']);
				}

				if ($share?->getType() === Share::TYPE_ADMIN) {
					$user = new Admin($data['polls_polls_owner']);
				} else {
					$user = new User($data['polls_polls_owner']);
				}

				$poll->setVote($vote);
				$poll->setUser($user);
				try {
					$this->acl->setPoll($poll);
					$this->acl->setShare($share);
					$relevantThreshold = $poll->getRelevantThresholdNet() + $this->preferences->getRelevantOffsetTimestamp();

					// mix poll settings, currentUser attributes, permissions and relevantThreshold into one array
					$pollList[] = (object) array_merge(
						(array) json_decode(json_encode($poll)),
						[
							'relevantThreshold' => $relevantThreshold,
							'relevantThresholdNet' => $poll->getRelevantThresholdNet(),
							'permissions' => $this->acl->getPermissionsArray(true),
							'currentUser' => $this->acl->getCurrentUserArray(),
						],
					);
				} catch (ForbiddenException $e) {
					continue;
				}
			}
		} catch (DoesNotExistException $e) {
			// silent catch
		}
		return $pollList;
	}

	/**
	 * Get list of polls
	 */
	public function search(ISearchQuery $query): array {
		$pollList = [];
		try {
			$polls = $this->pollMapper->search($query);

			foreach ($polls as $poll) {
				try {
					$this->acl->setPollId($poll->getId());
					// TODO: Not the elegant way. Improvement neccessary
					$pollList[] = $poll;
				} catch (ForbiddenException $e) {
					continue;
				}
			}
		} catch (DoesNotExistException $e) {
			// silent catch
		}
		return $pollList;
	}

	/**
	 * Get list of polls
	 * @return Poll[]
	 */
	public function listForAdmin(): array {
		$pollList = [];
		if ($this->userMapper->getCurrentUserCached()->getIsAdmin()) {
			try {
				$pollList = $this->pollMapper->findForAdmin($this->userMapper->getCurrentUserCached()->getId());
			} catch (DoesNotExistException $e) {
				// silent catch
			}
		}
		return $pollList;
	}

	/**
	 * Update poll configuration
	 * @return Poll
	 */
	public function takeover(int $pollId, UserBase $targetUser): Poll {
		$this->poll = $this->pollMapper->find($pollId);

		$this->eventDispatcher->dispatchTyped(new PollTakeOverEvent($this->poll));

		$this->poll->setOwner($targetUser->getId());
		$this->pollMapper->update($this->poll);

		return $this->poll;
	}

	/**
	 * @return Poll[]
	 * @psalm-return array<Poll>
	 */
	public function transferPolls(string $sourceUser, string $targetUser): array {
		try {
			$this->userMapper->getUserFromUserBase($targetUser);
		} catch (UserNotFoundException $e) {
			throw new InvalidUsernameException('The user id "' . $targetUser . '" for the target user is not valid.');
		}

		$pollsToTransfer = $this->pollMapper->listByOwner($sourceUser);

		foreach ($pollsToTransfer as &$poll) {
			$poll = $this->executeTransfer($poll, $targetUser);
		}
		return $pollsToTransfer;
	}

	/**
	 * @return Poll
	 */
	public function transferPoll(int $pollId, string $targetUser): Poll {
		try {
			$this->userMapper->getUserFromUserBase($targetUser);
		} catch (UserNotFoundException $e) {
			throw new InvalidUsernameException('The user id "' . $targetUser . '" for the target user is not valid.');
		}

		return $this->executeTransfer($this->pollMapper->find($pollId), $targetUser);
	}

	private function executeTransfer(Poll $poll, string $targetUser): Poll {
		$poll->setOwner($targetUser);
		$this->pollMapper->update($poll);
		$this->eventDispatcher->dispatchTyped(new PollOwnerChangeEvent($poll));
		return $poll;

	}
	/**
	 * get poll configuration
	 * @return Poll
	 */
	public function get(int $pollId) {
		$this->acl->setPollId($pollId);
		$this->poll = $this->pollMapper->find($pollId);
		return $this->poll;
	}

	/**
	 * Add poll
	 */
	public function add(string $type, string $title): Poll {
		if (!$this->appSettings->getPollCreationAllowed()) {
			throw new ForbiddenException('Poll creation is disabled');
		}

		// Validate valuess
		if (!in_array($type, $this->getValidPollType())) {
			throw new InvalidPollTypeException('Invalid poll type');
		}

		if (!$title) {
			throw new EmptyTitleException('Title must not be empty');
		}

		$this->poll = new Poll();
		$this->poll->setType($type);
		$this->poll->setCreated(time());
		$this->poll->setOwner($this->userMapper->getCurrentUserCached()->getId());
		$this->poll->setTitle($title);
		$this->poll->setDescription('');
		$this->poll->setAccess(Poll::ACCESS_PRIVATE);
		$this->poll->setExpire(0);
		$this->poll->setAnonymous(0);
		$this->poll->setAllowMaybe(0);
		$this->poll->setVoteLimit(0);
		$this->poll->setShowResults(Poll::SHOW_RESULTS_ALWAYS);
		$this->poll->setDeleted(0);
		$this->poll->setAdminAccess(0);
		$this->poll->setLastInteraction(time());
		$this->poll = $this->pollMapper->insert($this->poll);

		$this->eventDispatcher->dispatchTyped(new PollCreatedEvent($this->poll));

		return $this->poll;
	}

	/**
	 * Update poll configuration
	 * @return Poll
	 */
	public function update(int $pollId, array $poll): Poll {
		$this->poll = $this->pollMapper->find($pollId);

		// Validate valuess
		if (isset($poll['showResults']) && !in_array($poll['showResults'], $this->getValidShowResults())) {
			throw new InvalidShowResultsException('Invalid value for prop showResults');
		}

		if (isset($poll['title']) && !$poll['title']) {
			throw new EmptyTitleException('Title must not be empty');
		}

		if (isset($poll['access']) && !in_array($poll['access'], $this->getValidAccess())) {
			if (!in_array($poll['access'], $this->getValidAccess())) {
				throw new InvalidAccessException('Invalid value for prop access ' . $poll['access']);
			}

			if ($poll['access'] === (Poll::ACCESS_OPEN)) {
				$this->acl->setPollId($pollId, Acl::PERMISSION_ALL_ACCESS);
			}
		}

		// Set the expiry time to the actual servertime to avoid an
		// expiry misinterpration when using acl
		if (isset($poll['expire']) && $poll['expire'] < 0) {
			$poll['expire'] = time();
		}

		$this->poll->deserializeArray($poll);
		$this->pollMapper->update($this->poll);
		$this->eventDispatcher->dispatchTyped(new PollUpdatedEvent($this->poll));

		return $this->poll;
	}

	/**
	 * Update timestamp for last interaction with polls
	 */
	public function setLastInteraction(int $pollId): void {
		if ($pollId) {
			$this->pollMapper->setLastInteraction($pollId);
		}
	}


	/**
	 * Move to archive or restore
	 * @return Poll
	 */
	public function toggleArchive(int $pollId): Poll {
		$this->acl->setPollId($pollId, Acl::PERMISSION_POLL_DELETE);
		$this->poll = $this->acl->getPoll();

		$this->poll->setDeleted($this->poll->getDeleted() ? 0 : time());
		$this->poll = $this->pollMapper->update($this->poll);

		if ($this->poll->getDeleted()) {
			$this->eventDispatcher->dispatchTyped(new PollArchivedEvent($this->poll));
		} else {
			$this->eventDispatcher->dispatchTyped(new PollRestoredEvent($this->poll));
		}

		return $this->poll;
	}

	/**
	 * Delete poll
	 * @return Poll
	 */
	public function delete(int $pollId): Poll {
		$this->acl->setPollId($pollId, Acl::PERMISSION_POLL_DELETE);
		$this->poll = $this->acl->getPoll();

		$this->eventDispatcher->dispatchTyped(new PollDeletedEvent($this->poll));

		$this->pollMapper->delete($this->poll);

		return $this->poll;
	}

	/**
	 * Close poll
	 * @return Poll
	 */
	public function close(int $pollId): Poll {
		return $this->toggleClose($pollId, time() - 5);
	}

	/**
	 * Reopen poll
	 * @return Poll
	 */
	public function reopen(int $pollId): Poll {
		return $this->toggleClose($pollId, 0);
	}

	/**
	 * Close poll
	 * @return Poll
	 */
	private function toggleClose(int $pollId, int $expiry): Poll {
		$this->poll = $this->pollMapper->find($pollId);
		$this->acl->setPollId($this->poll->getId(), Acl::PERMISSION_POLL_EDIT);
		$this->poll->setExpire($expiry);
		if ($expiry > 0) {
			$this->eventDispatcher->dispatchTyped(new PollCloseEvent($this->poll));
		} else {
			$this->eventDispatcher->dispatchTyped(new PollReopenEvent($this->poll));
		}

		$this->poll = $this->pollMapper->update($this->poll);

		return $this->poll;
	}

	/**
	 * Clone poll
	 * @return Poll
	 */
	public function clone(int $pollId): Poll {
		$this->acl->setPollId($pollId);
		$origin = $this->acl->getPoll();

		$this->poll = new Poll();
		$this->poll->setCreated(time());
		$this->poll->setOwner($this->userMapper->getCurrentUserCached()->getId());
		$this->poll->setTitle('Clone of ' . $origin->getTitle());
		$this->poll->setDeleted(0);
		$this->poll->setAccess(Poll::ACCESS_PRIVATE);

		$this->poll->setType($origin->getType());
		$this->poll->setDescription($origin->getDescription());
		$this->poll->setExpire($origin->getExpire());
		$this->poll->setAnonymous($origin->getAnonymous());
		$this->poll->setAllowMaybe($origin->getAllowMaybe());
		$this->poll->setVoteLimit($origin->getVoteLimit());
		$this->poll->setShowResults($origin->getShowResults());
		$this->poll->setAdminAccess($origin->getAdminAccess());

		$this->poll = $this->pollMapper->insert($this->poll);
		$this->eventDispatcher->dispatchTyped(new PollCreatedEvent($this->poll));
		return $this->poll;
	}

	/**
	 * Collect email addresses from particitipants
	 *
	 */
	public function getParticipantsEmailAddresses(int $pollId): array {
		$this->acl->setPollId($pollId, Acl::PERMISSION_POLL_EDIT);
		$this->poll = $this->acl->getPoll();

		$votes = $this->voteMapper->findParticipantsByPoll($this->poll->getId());
		$list = [];
		foreach ($votes as $vote) {
			$user = $vote->getUser();
			$list[] = [
				'displayName' => $user->getDisplayName(),
				'emailAddress' => $user->getEmailAddress(),
				'combined' => $user->getEmailAndDisplayName(),
			];
		}
		return $list;
	}

	/**
	 * Get valid values for configuration options
	 *
	 * @return array
	 *
	 * @psalm-return array{pollType: mixed, access: mixed, showResults: mixed}
	 */
	public function getValidEnum(): array {
		return [
			'pollType' => $this->getValidPollType(),
			'access' => $this->getValidAccess(),
			'showResults' => $this->getValidShowResults()
		];
	}

	/**
	 * Get valid values for pollType
	 *
	 * @return string[]
	 *
	 * @psalm-return array{0: string, 1: string}
	 */
	private function getValidPollType(): array {
		return [Poll::TYPE_DATE, Poll::TYPE_TEXT];
	}

	/**
	 * Get valid values for access
	 *
	 * @return string[]
	 *
	 * @psalm-return array{0: string, 1: string}
	 */
	private function getValidAccess(): array {
		return [Poll::ACCESS_PRIVATE, Poll::ACCESS_OPEN];
	}

	/**
	 * Get valid values for showResult
	 *
	 * @return string[]
	 *
	 * @psalm-return array{0: string, 1: string, 2: string}
	 */
	private function getValidShowResults(): array {
		return [Poll::SHOW_RESULTS_ALWAYS, Poll::SHOW_RESULTS_CLOSED, Poll::SHOW_RESULTS_NEVER];
	}
}
