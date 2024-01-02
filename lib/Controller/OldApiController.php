<?php
/**
 * Nextcloud - cospend
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2019
 */

namespace OCA\Cospend\Controller;

use DateTime;
use OCA\Cospend\Attribute\CospendPublicAuth;
use OCA\Cospend\Attribute\CospendUserPermissions;
use OCP\AppFramework\Http;
use OCP\DB\Exception;
use OCP\IConfig;
use OCP\IL10N;

use OCP\AppFramework\Http\ContentSecurityPolicy;

use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\ApiController;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

use OCA\Cospend\Db\BillMapper;
use OCA\Cospend\Service\ProjectService;
use OCA\Cospend\Activity\ActivityManager;
use OCA\Cospend\AppInfo\Application;

class OldApiController extends ApiController {

	public function __construct(
		string $appName,
		IRequest $request,
		private IL10N $trans,
		private BillMapper $billMapper,
		private ProjectService $projectService,
		private ActivityManager $activityManager,
		private IDBConnection $dbconnection,
		public ?string $userId
	) {
		parent::__construct(
			$appName, $request,
			'PUT, POST, GET, DELETE, PATCH, OPTIONS',
			'Authorization, Content-Type, Accept',
			1728000
		);
	}

	// TODO get rid of checkLogin and switch to middleware auth checks (pub and priv) like in new controllers
	// project main passwords can't be edited anymore anyway
	// TODO get rid of anonymous project creation stuff (toggle and routes/contr methods)

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_ADMIN)]
	public function apiPrivSetProjectInfo(string $projectId, ?string $name = null, ?string $contact_email = null, ?string $password = null,
										  ?string $autoexport = null, ?string $currencyname = null, ?bool $deletion_disabled = null,
										  ?string $categorysort = null, ?string $paymentmodesort = null): DataResponse {
		$result = $this->projectService->editProject(
			$projectId, $name, $contact_email, $password, $autoexport,
			$currencyname, $deletion_disabled, $categorysort, $paymentmodesort
		);
		if (isset($result['success'])) {
			return new DataResponse('UPDATED');
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	public function apiPrivCreateProject(string $name, string $id, ?string $password = null, ?string $contact_email = null): DataResponse {
		$result = $this->projectService->createProject($name, $id, $password, $contact_email, $this->userId);
		if (isset($result['id'])) {
			return new DataResponse($result['id']);
		} else {
			return new DataResponse($result, Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_VIEWER)]
	public function apiGetProjectInfo(string $token): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$projectInfo = $this->projectService->getProjectInfo($publicShareInfo['projectid']);
		if ($projectInfo !== null) {
			unset($projectInfo['userid']);
			// for public link share: set the visible access level for frontend
			if ($publicShareInfo !== null) {
				$projectInfo['myaccesslevel'] = $publicShareInfo['accesslevel'];
			} else {
				// my access level is the guest one
				$projectInfo['myaccesslevel'] = $projectInfo['guestaccesslevel'];
			}
			return new DataResponse($projectInfo);
		}
		return new DataResponse(
			['message' => $this->trans->t('Project not found')],
			Http::STATUS_NOT_FOUND
		);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_VIEWER)]
	public function apiPrivGetProjectInfo(string $projectId): DataResponse {
		$projectInfo = $this->projectService->getProjectInfo($projectId);
		if ($projectInfo !== null) {
			unset($projectInfo['userid']);
			$projectInfo['myaccesslevel'] = $this->projectService->getUserMaxAccessLevel($this->userId, $projectId);
			return new DataResponse($projectInfo);
		}
		return new DataResponse(
			['message' => $this->trans->t('Project not found')],
			Http::STATUS_NOT_FOUND
		);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_ADMIN)]
	public function apiSetProjectInfo(string $token, ?string $name = null, ?string $contact_email = null,
									  ?string $autoexport = null, ?string $currencyname = null,
									  ?bool $deletion_disabled = null, ?string $categorysort = null, ?string $paymentmodesort = null): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->editProject(
			$publicShareInfo['projectid'], $name, $contact_email, null, $autoexport,
			$currencyname, $deletion_disabled, $categorysort, $paymentmodesort
		);
		if (isset($result['success'])) {
			return new DataResponse('UPDATED');
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_VIEWER)]
	public function apiGetMembers(string $token, ?int $lastchanged = null): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$members = $this->projectService->getMembers($publicShareInfo['projectid'], null, $lastchanged);
		return new DataResponse($members);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_VIEWER)]
	public function apiPrivGetMembers(string $projectId, ?int $lastchanged = null): DataResponse {
		$members = $this->projectService->getMembers($projectId, null, $lastchanged);
		return new DataResponse($members);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_VIEWER)]
	public function apiGetBills(string $token, ?int $lastchanged = null,
								?int $offset = 0, ?int $limit = null, bool $reverse = false, ?int $deleted = 0): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		if ($limit) {
			$bills = $this->billMapper->getBillsWithLimit(
				$publicShareInfo['projectid'], null, null,
				null, null, null, null, null,
				$lastchanged, $limit, $reverse, $offset, null, null, null, $deleted
			);
		} else {
			$bills = $this->billMapper->getBills(
				$publicShareInfo['projectid'], null, null,
				null, null, null, null, null,
				$lastchanged, null, $reverse, null, $deleted
			);
		}
		return new DataResponse($bills);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @param string $token
	 * @param int|null $lastchanged
	 * @param int|null $offset
	 * @param int|null $limit
	 * @param bool $reverse
	 * @param int|null $payerId
	 * @return DataResponse
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_VIEWER)]
	public function apiv3GetBills(
		string $token, ?int $lastchanged = null, ?int $offset = 0, ?int $limit = null, bool $reverse = false,
		?int $payerId = null, ?int $categoryId = null, ?int $paymentModeId = null, ?int $includeBillId = null,
		?string $searchTerm = null, ?int $deleted = 0
	): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		if ($limit) {
			$bills = $this->billMapper->getBillsWithLimit(
				$publicShareInfo['projectid'], null, null,
				null, $paymentModeId, $categoryId, null, null,
				$lastchanged, $limit, $reverse, $offset, $payerId, $includeBillId, $searchTerm, $deleted
			);
		} else {
			$bills = $this->billMapper->getBills(
				$publicShareInfo['projectid'], null, null,
				null, $paymentModeId, $categoryId, null, null,
				$lastchanged, null, $reverse, $payerId, $deleted
			);
		}
		$result = [
			'nb_bills' => $this->billMapper->countBills(
				$publicShareInfo['projectid'], $payerId, $categoryId, $paymentModeId, $deleted
			),
			'bills' => $bills,
		];
		return new DataResponse($result);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_VIEWER)]
	public function apiPrivGetBills(string $projectId, ?int $lastchanged = null, ?int $deleted = 0): DataResponse {
		$bills = $this->billMapper->getBills(
			$projectId, null, null, null, null, null,
			null, null, $lastchanged, null, false, null, $deleted
		);
		$billIds = $this->projectService->getAllBillIds($projectId, $deleted);
		$ts = (new DateTime())->getTimestamp();
		return new DataResponse([
			'bills' => $bills,
			'allBillIds' => $billIds,
			'timestamp' => $ts,
		]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_VIEWER)]
	public function apiv2GetBills(string $token, ?int $lastchanged = null, ?int $deleted = 0): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$bills = $this->billMapper->getBills(
			$publicShareInfo['projectid'], null, null,
			null, null, null, null, null, $lastchanged,
			null, false, null, $deleted
		);
		$billIds = $this->projectService->getAllBillIds($publicShareInfo['projectid'], $deleted);
		$ts = (new DateTime())->getTimestamp();
		return new DataResponse([
			'bills' => $bills,
			'allBillIds' => $billIds,
			'timestamp' => $ts,
		]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiAddMember(string $token, string $name,
								 float  $weight = 1, int $active = 1, ?string $color = null): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->createMember(
			$publicShareInfo['projectid'], $name, $weight, $active !== 0, $color, null
		);
		if (!isset($result['error'])) {
			return new DataResponse($result['id']);
		} else {
			return new DataResponse($result['error'], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiv2AddMember(string $token, string $name, float $weight = 1, int $active = 1,
								   ?string $color = null, ?string $userid = null): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->createMember(
			$publicShareInfo['projectid'], $name, $weight, $active !== 0, $color, $userid
		);
		if (!isset($result['error'])) {
			return new DataResponse($result);
		}
		return new DataResponse($result['error'], Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiPrivAddMember(string $projectId, string $name, float $weight = 1, int $active = 1,
									 ?string $color = null, ?string $userid = null): DataResponse {
		$result = $this->projectService->createMember($projectId, $name, $weight, $active !== 0, $color, $userid);
		if (!isset($result['error'])) {
			return new DataResponse($result['id']);
		}
		return new DataResponse($result['error'], Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_PARTICIPANT)]
	public function apiAddBill(string $token, ?string $date = null, ?string $what = null, ?int $payer = null,
							   ?string $payed_for = null, ?float $amount = null, string $repeat = 'n',
							   ?string $paymentmode = null, ?int $paymentmodeid = null,
							   ?int $categoryid = null, int $repeatallactive = 0, ?string $repeatuntil = null, ?int $timestamp = null,
							   ?string $comment = null, ?int $repeatfreq = null): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->createBill(
			$publicShareInfo['projectid'], $date, $what, $payer, $payed_for, $amount,
			$repeat, $paymentmode, $paymentmodeid, $categoryid, $repeatallactive,
			$repeatuntil, $timestamp, $comment, $repeatfreq
		);
		if (isset($result['inserted_id'])) {
			$billObj = $this->billMapper->find($result['inserted_id']);
			if (is_null($publicShareInfo)) {
				$authorFullText = $this->trans->t('Guest access');
			} elseif ($publicShareInfo['label']) {
				$authorName = $publicShareInfo['label'];
				$authorFullText = $this->trans->t('Share link (%s)', [$authorName]);
			} else {
				$authorFullText = $this->trans->t('Share link');
			}
			$this->activityManager->triggerEvent(
				ActivityManager::COSPEND_OBJECT_BILL, $billObj,
				ActivityManager::SUBJECT_BILL_CREATE,
				['author' => $authorFullText]
			);
			return new DataResponse($result['inserted_id']);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_PARTICIPANT)]
	public function apiPrivAddBill(string  $projectId, ?string $date = null, ?string $what = null, ?int $payer = null,
								   ?string $payed_for = null, ?float $amount = null, string $repeat = 'n',
								   ?string $paymentmode = null, ?int $paymentmodeid = null,
								   ?int $categoryid = null, int $repeatallactive = 0, ?string $repeatuntil = null, ?int $timestamp = null,
								   ?string $comment = null, ?int $repeatfreq = null): DataResponse {
		$result = $this->projectService->createBill($projectId, $date, $what, $payer, $payed_for, $amount,
												 $repeat, $paymentmode, $paymentmodeid, $categoryid, $repeatallactive,
												 $repeatuntil, $timestamp, $comment, $repeatfreq);
		if (isset($result['inserted_id'])) {
			$billObj = $this->billMapper->find($result['inserted_id']);
			$this->activityManager->triggerEvent(
				ActivityManager::COSPEND_OBJECT_BILL, $billObj,
				ActivityManager::SUBJECT_BILL_CREATE,
				[]
			);
			return new DataResponse($result['inserted_id']);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_PARTICIPANT)]
	public function apiRepeatBill(string $token, int $billId): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$bill = $this->billMapper->getBill($publicShareInfo['projectid'], $billId);
		if ($bill === null) {
			return new DataResponse('Bill not found', Http::STATUS_NOT_FOUND);
		}
		$result = $this->projectService->cronRepeatBills($billId);
		return new DataResponse($result);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_PARTICIPANT)]
	public function apiEditBill(
		string  $token, int $billid, ?string $date = null, ?string $what = null,
		?int $payer = null, ?string $payed_for = null, ?float $amount = null, string $repeat = 'n',
		?string $paymentmode = null, ?int $paymentmodeid = null,
		?int $categoryid = null, ?int $repeatallactive = null,
		?string $repeatuntil = null, ?int $timestamp = null, ?string $comment = null,
		?int $repeatfreq = null, ?int $deleted = null
	): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->editBill(
			$publicShareInfo['projectid'], $billid, $date, $what, $payer, $payed_for,
			$amount, $repeat, $paymentmode, $paymentmodeid, $categoryid,
			$repeatallactive, $repeatuntil, $timestamp, $comment, $repeatfreq, null, $deleted
		);
		if (isset($result['edited_bill_id'])) {
			$billObj = $this->billMapper->find($billid);
			if (is_null($publicShareInfo)) {
				$authorFullText = $this->trans->t('Guest access');
			} elseif ($publicShareInfo['label']) {
				$authorName = $publicShareInfo['label'];
				$authorFullText = $this->trans->t('Share link (%s)', [$authorName]);
			} else {
				$authorFullText = $this->trans->t('Share link');
			}
			$this->activityManager->triggerEvent(
				ActivityManager::COSPEND_OBJECT_BILL, $billObj,
				ActivityManager::SUBJECT_BILL_UPDATE,
				['author' => $authorFullText]
			);

			return new DataResponse($result['edited_bill_id']);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_PARTICIPANT)]
	public function apiEditBills(
		string $token, array $billIds, ?int $categoryid = null, ?string $date = null,
		?string $what = null, ?int $payer = null, ?string $payed_for = null, ?float $amount = null,
		?string $repeat = 'n', ?string $paymentmode = null, ?int $paymentmodeid = null,
		?int $repeatallactive = null,
		?string $repeatuntil = null, ?int $timestamp = null, ?string $comment = null,
		?int $repeatfreq = null, ?int $deleted = null
	): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		if (is_null($publicShareInfo)) {
			$authorFullText = $this->trans->t('Guest access');
		} elseif ($publicShareInfo['label']) {
			$authorName = $publicShareInfo['label'];
			$authorFullText = $this->trans->t('Share link (%s)', [$authorName]);
		} else {
			$authorFullText = $this->trans->t('Share link');
		}
		$paymentModes = $this->projectService->getCategoriesOrPaymentModes($publicShareInfo['projectid'], false);
		foreach ($billIds as $billid) {
			$result = $this->projectService->editBill(
				$publicShareInfo['projectid'], $billid, $date, $what, $payer, $payed_for,
				$amount, $repeat, $paymentmode, $paymentmodeid, $categoryid,
				$repeatallactive, $repeatuntil, $timestamp, $comment, $repeatfreq, $paymentModes, $deleted
			);
			if (isset($result['edited_bill_id'])) {
				$billObj = $this->billMapper->find($billid);
				$this->activityManager->triggerEvent(
					ActivityManager::COSPEND_OBJECT_BILL, $billObj,
					ActivityManager::SUBJECT_BILL_UPDATE,
					['author' => $authorFullText]
				);
			} else {
				return new DataResponse($result, Http::STATUS_BAD_REQUEST);
			}
		}
		return new DataResponse($billIds);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_PARTICIPANT)]
	public function apiPrivEditBill(
		string $projectId, int $billid, ?string $date = null, ?string $what = null,
		?int $payer = null, ?string $payed_for = null, ?float $amount = null, ?string $repeat = 'n',
		?string $paymentmode = null, ?int $paymentmodeid = null,
		?int $categoryid = null, ?int $repeatallactive = null,
		?string $repeatuntil = null, ?int $timestamp = null, ?string $comment=null,
		?int $repeatfreq = null, ?int $deleted = null
	): DataResponse {
		$result = $this->projectService->editBill(
			$projectId, $billid, $date, $what, $payer, $payed_for,
			$amount, $repeat, $paymentmode, $paymentmodeid, $categoryid,
			$repeatallactive, $repeatuntil, $timestamp, $comment, $repeatfreq, null, $deleted
		);
		if (isset($result['edited_bill_id'])) {
			$billObj = $this->billMapper->find($billid);
			$this->activityManager->triggerEvent(
				ActivityManager::COSPEND_OBJECT_BILL, $billObj,
				ActivityManager::SUBJECT_BILL_UPDATE,
				[]
			);

			return new DataResponse($result['edited_bill_id']);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_PARTICIPANT)]
	public function apiClearTrashbin(string $token): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		try {
			$this->billMapper->deleteDeletedBills($publicShareInfo['projectid']);
			return new DataResponse('');
		} catch (\Exception | \Throwable $e) {
			return new DataResponse('', Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_PARTICIPANT)]
	public function apiDeleteBill(string $token, int $billid, bool $moveToTrash = true): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$billObj = null;
		if ($this->billMapper->getBill($publicShareInfo['projectid'], $billid) !== null) {
			$billObj = $this->billMapper->find($billid);
		}

		$result = $this->projectService->deleteBill($publicShareInfo['projectid'], $billid, false, $moveToTrash);
		if (isset($result['success'])) {
			if (!is_null($billObj)) {
				if (is_null($publicShareInfo)) {
					$authorFullText = $this->trans->t('Guest access');
				} elseif ($publicShareInfo['label']) {
					$authorName = $publicShareInfo['label'];
					$authorFullText = $this->trans->t('Share link (%s)', [$authorName]);
				} else {
					$authorFullText = $this->trans->t('Share link');
				}
				$this->activityManager->triggerEvent(
					ActivityManager::COSPEND_OBJECT_BILL, $billObj,
					ActivityManager::SUBJECT_BILL_DELETE,
					['author' => $authorFullText]
				);
			}
			return new DataResponse('OK');
		}
		return new DataResponse($result, Http::STATUS_NOT_FOUND);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_PARTICIPANT)]
	public function apiDeleteBills(string $token, array $billIds, bool $moveToTrash = true): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		if (is_null($publicShareInfo)) {
			$authorFullText = $this->trans->t('Guest access');
		} elseif ($publicShareInfo['label']) {
			$authorName = $publicShareInfo['label'];
			$authorFullText = $this->trans->t('Share link (%s)', [$authorName]);
		} else {
			$authorFullText = $this->trans->t('Share link');
		}
		foreach ($billIds as $billId) {
			$billObj = null;
			if ($this->billMapper->getBill($publicShareInfo['projectid'], $billId) !== null) {
				$billObj = $this->billMapper->find($billId);
			}

			$result = $this->projectService->deleteBill($publicShareInfo['projectid'], $billId, false, $moveToTrash);
			if (!isset($result['success'])) {
				return new DataResponse($result, Http::STATUS_NOT_FOUND);
			} else {
				if (!is_null($billObj)) {
					$this->activityManager->triggerEvent(
						ActivityManager::COSPEND_OBJECT_BILL, $billObj,
						ActivityManager::SUBJECT_BILL_DELETE,
						['author' => $authorFullText]
					);
				}
			}
		}
		return new DataResponse('OK');
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_PARTICIPANT)]
	public function apiPrivClearTrashbin(string $projectId): DataResponse {
		try {
			$this->billMapper->deleteDeletedBills($projectId);
			return new DataResponse('');
		} catch (\Exception | \Throwable $e) {
			return new DataResponse('', Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_PARTICIPANT)]
	public function apiPrivDeleteBill(string $projectId, int $billid, bool $moveToTrash = true): DataResponse {
		$billObj = null;
		if ($this->billMapper->getBill($projectId, $billid) !== null) {
			$billObj = $this->billMapper->find($billid);
		}

		$result = $this->projectService->deleteBill($projectId, $billid, false, $moveToTrash);
		if (isset($result['success'])) {
			if (!is_null($billObj)) {
				$this->activityManager->triggerEvent(
					ActivityManager::COSPEND_OBJECT_BILL, $billObj,
					ActivityManager::SUBJECT_BILL_DELETE,
					[]
				);
			}
			return new DataResponse('OK');
		}
		return new DataResponse($result, Http::STATUS_NOT_FOUND);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiDeleteMember(string $token, int $memberid): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->deleteMember($publicShareInfo['projectid'], $memberid);
		if (isset($result['success'])) {
			return new DataResponse('OK');
		}
		return new DataResponse($result, Http::STATUS_NOT_FOUND);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiPrivDeleteMember(string $projectId, int $memberid): DataResponse {
		$result = $this->projectService->deleteMember($projectId, $memberid);
		if (isset($result['success'])) {
			return new DataResponse('OK');
		}
		return new DataResponse($result, Http::STATUS_NOT_FOUND);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_ADMIN)]
	public function apiDeleteProject(string $token): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->deleteProject($publicShareInfo['projectid']);
		if (!isset($result['error'])) {
			return new DataResponse($result);
		}
		return new DataResponse(['message' => $result['error']], Http::STATUS_NOT_FOUND);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_ADMIN)]
	public function apiPrivDeleteProject(string $projectId): DataResponse {
		$result = $this->projectService->deleteProject($projectId);
		if (!isset($result['error'])) {
			return new DataResponse($result);
		}
		return new DataResponse(['message' => $result['error']], Http::STATUS_NOT_FOUND);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiEditMember(string $token, int $memberid,
								  ?string $name = null, ?float $weight = null, $activated = null,
								  ?string $color = null, ?string $userid = null): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		if ($activated === 'true') {
			$activated = true;
		} elseif ($activated === 'false') {
			$activated = false;
		}
		$result = $this->projectService->editMember(
			$publicShareInfo['projectid'], $memberid, $name, $userid, $weight, $activated, $color
		);
		if (count($result) === 0) {
			return new DataResponse(null);
		} elseif (array_key_exists('activated', $result)) {
			return new DataResponse($result);
		} else {
			return new DataResponse($result, Http::STATUS_FORBIDDEN);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiPrivEditMember(string $projectId, int $memberid, ?string $name = null, ?float $weight = null,
										$activated = null, ?string $color = null, ?string $userid = null): DataResponse {
		if ($activated === 'true') {
			$activated = true;
		} elseif ($activated === 'false') {
			$activated = false;
		}
		$result = $this->projectService->editMember($projectId, $memberid, $name, $userid, $weight, $activated, $color);
		if (count($result) === 0) {
			return new DataResponse(null);
		} elseif (array_key_exists('activated', $result)) {
			return new DataResponse($result);
		} else {
			return new DataResponse($result, Http::STATUS_FORBIDDEN);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @param string $token
	 * @param int|null $tsMin
	 * @param int|null $tsMax
	 * @param int|null $paymentModeId
	 * @param int|null $categoryId
	 * @param float|null $amountMin
	 * @param float|null $amountMax
	 * @param string $showDisabled
	 * @param int|null $currencyId
	 * @param int|null $payerId
	 * @return DataResponse
	 * @throws Exception
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_VIEWER)]
	public function apiGetProjectStatistics(string $token, ?int $tsMin = null, ?int $tsMax = null,
											?int $paymentModeId = null, ?int $categoryId = null,
											?float $amountMin = null, ?float $amountMax=null,
											string $showDisabled = '1', ?int $currencyId = null,
											?int $payerId = null): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->getProjectStatistics(
			$publicShareInfo['projectid'], 'lowername', $tsMin, $tsMax,
			$paymentModeId, $categoryId, $amountMin, $amountMax, $showDisabled === '1', $currencyId,
			$payerId
		);
		return new DataResponse($result);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 *
	 * @param string $projectId
	 * @param int|null $tsMin
	 * @param int|null $tsMax
	 * @param int|null $paymentModeId
	 * @param int|null $categoryId
	 * @param float|null $amountMin
	 * @param float|null $amountMax
	 * @param string $showDisabled
	 * @param int|null $currencyId
	 * @param int|null $payerId
	 * @return DataResponse
	 * @throws Exception
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_VIEWER)]
	public function apiPrivGetProjectStatistics(string $projectId, ?int $tsMin = null, ?int $tsMax = null,
												?int $paymentModeId = null,
												?int $categoryId = null, ?float $amountMin = null, ?float $amountMax = null,
												string $showDisabled = '1', ?int $currencyId = null,
												?int $payerId = null): DataResponse {
		$result = $this->projectService->getProjectStatistics(
			$projectId, 'lowername', $tsMin, $tsMax, $paymentModeId,
			$categoryId, $amountMin, $amountMax, $showDisabled === '1', $currencyId, $payerId
		);
		return new DataResponse($result);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_VIEWER)]
	public function apiGetProjectSettlement(string $token, ?int $centeredOn = null, ?int $maxTimestamp = null): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->getProjectSettlement(
			$publicShareInfo['projectid'], $centeredOn, $maxTimestamp
		);
		return new DataResponse($result);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_VIEWER)]
	public function apiPrivGetProjectSettlement(string $projectId, ?int $centeredOn = null, ?int $maxTimestamp = null): DataResponse {
		$result = $this->projectService->getProjectSettlement($projectId, $centeredOn, $maxTimestamp);
		return new DataResponse($result);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_PARTICIPANT)]
	public function apiAutoSettlement(string $token, ?int $centeredOn = null,
									  int $precision = 2, ?int $maxTimestamp = null): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->autoSettlement(
			$publicShareInfo['projectid'], $centeredOn, $precision, $maxTimestamp
		);
		if (isset($result['success'])) {
			return new DataResponse('OK');
		}
		return new DataResponse($result, Http::STATUS_FORBIDDEN);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_PARTICIPANT)]
	public function apiPrivAutoSettlement(string $projectId, ?int $centeredOn = null, int $precision = 2, ?int $maxTimestamp = null): DataResponse {
		$result = $this->projectService->autoSettlement($projectId, $centeredOn, $precision, $maxTimestamp);
		if (isset($result['success'])) {
			return new DataResponse('OK');
		}
		return new DataResponse($result, Http::STATUS_FORBIDDEN);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiAddPaymentMode(string $token, string $name, ?string $icon, string $color, ?int $order = 0): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->createPaymentMode(
			$publicShareInfo['projectid'], $name, $icon, $color, $order
		);
		if (is_numeric($result)) {
			return new DataResponse($result);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiPrivAddPaymentMode(string $projectId, string $name, ?string $icon = null, ?string $color = null): DataResponse {
		$result = $this->projectService->createPaymentMode($projectId, $name, $icon, $color);
		if (is_numeric($result)) {
			return new DataResponse($result);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiEditPaymentMode(string $token, int $pmid, ?string $name = null,
									   ?string $icon = null, ?string $color = null): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->editPaymentMode(
			$publicShareInfo['projectid'], $pmid, $name, $icon, $color
		);
		if (is_array($result)) {
			return new DataResponse($result);
		}
		return new DataResponse($result, Http::STATUS_FORBIDDEN);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiSavePaymentModeOrder(string $token, array $order): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		if ($this->projectService->savePaymentModeOrder($publicShareInfo['projectid'], $order)) {
			return new DataResponse(true);
		}
		return new DataResponse(false, Http::STATUS_FORBIDDEN);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiPrivEditPaymentMode(string $projectId, int $pmid, ?string $name = null,
										   ?string $icon = null, ?string $color = null): DataResponse {
		$result = $this->projectService->editPaymentMode($projectId, $pmid, $name, $icon, $color);
		if (is_array($result)) {
			return new DataResponse($result);
		}
		return new DataResponse($result, Http::STATUS_FORBIDDEN);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiDeletePaymentMode(string $token, int $pmid): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->deletePaymentMode($publicShareInfo['projectid'], $pmid);
		if (isset($result['success'])) {
			return new DataResponse($pmid);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiPrivDeletePaymentMode(string $projectId, int $pmid): DataResponse {
		$result = $this->projectService->deletePaymentMode($projectId, $pmid);
		if (isset($result['success'])) {
			return new DataResponse($pmid);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiAddCategory(string $token, string $name, ?string $icon, string $color, ?int $order = 0): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->createCategory(
			$publicShareInfo['projectid'], $name, $icon, $color, $order
		);
		if (is_numeric($result)) {
			// inserted category id
			return new DataResponse($result);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiPrivAddCategory(string $projectId, string $name, ?string $icon = null, ?string $color = null): DataResponse {
		$result = $this->projectService->createCategory($projectId, $name, $icon, $color);
		if (is_numeric($result)) {
			// inserted category id
			return new DataResponse($result);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiEditCategory(string $token, int $categoryid, ?string $name = null,
									?string $icon = null, ?string $color = null): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->editCategory(
			$publicShareInfo['projectid'], $categoryid, $name, $icon, $color
		);
		if (is_array($result)) {
			return new DataResponse($result);
		}
		return new DataResponse($result, Http::STATUS_FORBIDDEN);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiSaveCategoryOrder(string $token, array $order): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		if ($this->projectService->saveCategoryOrder($publicShareInfo['projectid'], $order)) {
			return new DataResponse(true);
		}
		return new DataResponse(false, Http::STATUS_FORBIDDEN);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiPrivEditCategory(string $projectId, int $categoryid, ?string $name = null,
										?string $icon = null, ?string $color = null): DataResponse {
		$result = $this->projectService->editCategory($projectId, $categoryid, $name, $icon, $color);
		if (is_array($result)) {
			return new DataResponse($result);
		}
		return new DataResponse($result, Http::STATUS_FORBIDDEN);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiDeleteCategory(string $token, int $categoryid): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->deleteCategory($publicShareInfo['projectid'], $categoryid);
		if (isset($result['success'])) {
			return new DataResponse($categoryid);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiPrivDeleteCategory(string $projectId, int $categoryid): DataResponse {
		$result = $this->projectService->deleteCategory($projectId, $categoryid);
		if (isset($result['success'])) {
			return new DataResponse($categoryid);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiAddCurrency(string $token, string $name, float $rate): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->createCurrency($publicShareInfo['projectid'], $name, $rate);
		if (is_numeric($result)) {
			// inserted currency id
			return new DataResponse($result);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiPrivAddCurrency(string $projectId, string $name, float $rate): DataResponse {
		$result = $this->projectService->createCurrency($projectId, $name, $rate);
		if (is_numeric($result)) {
			// inserted bill id
			return new DataResponse($result);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiEditCurrency(string $token, int $currencyid, string $name, float $rate): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->editCurrency(
			$publicShareInfo['projectid'], $currencyid, $name, $rate
		);
		if (!isset($result['message'])) {
			return new DataResponse($result);
		}
		return new DataResponse($result, Http::STATUS_FORBIDDEN);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiPrivEditCurrency(string $projectId, int $currencyid, string $name, float $rate): DataResponse {
		$result = $this->projectService->editCurrency($projectId, $currencyid, $name, $rate);
		if (!isset($result['message'])) {
			return new DataResponse($result);
		}
		return new DataResponse($result, Http::STATUS_FORBIDDEN);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 */
	#[CospendPublicAuth(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiDeleteCurrency(string $token, int $currencyid): DataResponse {
		$publicShareInfo = $this->projectService->getProjectInfoFromShareToken($token);
		$result = $this->projectService->deleteCurrency($publicShareInfo['projectid'], $currencyid);
		if (isset($result['success'])) {
			return new DataResponse($currencyid);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	#[CospendUserPermissions(minimumLevel: Application::ACCESS_LEVEL_MAINTAINER)]
	public function apiPrivDeleteCurrency(string $projectId, int $currencyid): DataResponse {
		$result = $this->projectService->deleteCurrency($projectId, $currencyid);
		if (isset($result['success'])) {
			return new DataResponse($currencyid);
		}
		return new DataResponse($result, Http::STATUS_BAD_REQUEST);
	}

	/**
	 * Used by MoneyBuster to check if weblogin is valid
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function apiPing(): DataResponse {
		$response = new DataResponse([$this->userId]);
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedImageDomain('*')
			->addAllowedMediaDomain('*')
			->addAllowedConnectDomain('*');
		$response->setContentSecurityPolicy($csp);
		return $response;
	}
}