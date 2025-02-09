<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\controllers;

use Craft;
use craft\base\Element;
use craft\errors\SiteNotFoundException;
use craft\helpers\Cp;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\queue\Queue;
use craft\web\Controller;
use craft\web\View;
use putyourlightson\campaign\assets\SendoutPreflightAsset;
use putyourlightson\campaign\assets\SendTestAsset;
use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\elements\ContactElement;
use putyourlightson\campaign\elements\SendoutElement;
use putyourlightson\campaign\fieldlayoutelements\sendouts\SendoutFieldLayoutElement;
use putyourlightson\campaign\helpers\SettingsHelper;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SendoutsController extends Controller
{
    /**
     * @inheritdoc
     */
    protected int|bool|array $allowAnonymous = ['queue-pending-sendouts'];

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if ($action->id != 'queue-pending-sendouts') {
            $this->requirePermission('campaign:sendouts');
        }

        return parent::beforeAction($action);
    }

    /**
     * Queues pending sendouts.
     */
    public function actionQueuePendingSendouts(): Response
    {
        // Require permission if posted from utility
        if ($this->request->getIsPost() && $this->request->getParam('utility')) {
            $this->requirePermission('utility:campaign');
        } else {
            // Verify API key
            $key = $this->request->getParam('key');
            $apiKey = Campaign::$plugin->settings->getApiKey();

            if ($key === null || empty($apiKey) || $key != $apiKey) {
                throw new ForbiddenHttpException('Unauthorised access.');
            }
        }

        $count = Campaign::$plugin->sendouts->queuePendingSendouts();

        if ($this->request->getParam('run')) {
            /** @var Queue $queue */
            $queue = Craft::$app->getQueue();
            $queue->run();
        }

        // If front-end site request
        if (Craft::$app->getView()->templateMode == View::TEMPLATE_MODE_SITE) {
            // Prep the response
            $this->response->content = (string)$count;

            return $this->response;
        }

        return $this->asSuccess(Craft::t('campaign', '{count} pending sendout(s) queued.', ['count' => $count]));
    }

    /**
     * Returns the pending recipient count.
     */
    public function actionGetPendingRecipientCount(): Response
    {
        $sendout = $this->getSendoutFromParamId();
        $this->response->content = number_format(Campaign::$plugin->sendouts->getPendingRecipientCount($sendout));

        return $this->response;
    }

    /**
     * Returns the HTML body.
     */
    public function actionGetHtmlBody(): Response
    {
        $sendout = $this->getSendoutFromParamId();
        $this->response->content = $sendout->getHtmlBody();

        return $this->response;
    }

    /**
     * Returns the plaintext body.
     */
    public function actionGetPlaintextBody(): Response
    {
        $sendout = $this->getSendoutFromParamId();
        $this->response->content = $sendout->getPlaintextBody();

        return $this->response;
    }

    /**
     * Creates a new unpublished draft and redirects to its edit page.
     *
     * @see CategoriesController::actionCreate()
     * @since 2.0.0
     */
    public function actionCreate(string $sendoutType = null): Response
    {
        if (!isset(SendoutElement::sendoutTypes()[$sendoutType])) {
            throw new BadRequestHttpException('Invalid sendout type: ' . $sendoutType);
        }

        $site = Cp::requestedSite();
        if (!$site) {
            throw new SiteNotFoundException();
        }

        // Create & populate the draft
        $sendout = Craft::createObject(SendoutElement::class);
        $sendout->siteId = $site->id;
        $sendout->sendoutType = $sendoutType;

        // Make sure the user is allowed to create this sendout
        $user = Craft::$app->getUser()->getIdentity();
        if (!$sendout->canSave($user)) {
            throw new ForbiddenHttpException('User not authorized to save this sendout.');
        }

        // Title & slug
        $sendout->title = $this->request->getQueryParam('title');
        $sendout->slug = $this->request->getQueryParam('slug');
        if ($sendout->title && !$sendout->slug) {
            $sendout->slug = ElementHelper::generateSlug($sendout->title, null, $site->language);
        }
        if (!$sendout->slug) {
            $sendout->slug = ElementHelper::tempSlug();
        }

        // If a campaign ID was passed in as a parameter (from campaign "save and send" button)
        if ($campaignId = $this->request->getParam('campaignId')) {
            $campaign = Campaign::$plugin->campaigns->getCampaignById($campaignId);
            if ($campaign) {
                $sendout->campaignId = $campaign->id;
                $sendout->title = $campaign->title;
                $sendout->subject = $campaign->title;
            }
        }

        // Save it
        $sendout->setScenario(Element::SCENARIO_ESSENTIALS);
        if (!Craft::$app->getDrafts()->saveElementAsDraft($sendout, Craft::$app->getUser()->getId(), null, null, false)) {
            return $this->asModelFailure($sendout, Craft::t('app', 'Couldn’t create {type}.', [
                'type' => SendoutElement::lowerDisplayName(),
            ]), 'sendout');
        }

        $editUrl = $sendout->getCpEditUrl();

        $response = $this->asModelSuccess($sendout, Craft::t('app', '{type} created.', [
            'type' => SendoutElement::displayName(),
        ]), 'sendout', array_filter([
            'cpEditUrl' => $this->request->isCpRequest ? $editUrl : null,
        ]));

        if (!$this->request->getAcceptsJson()) {
            $response->redirect(UrlHelper::urlWithParams($editUrl, [
                'fresh' => 1,
            ]));
        }

        return $response;
    }

    /**
     * Preview page.
     */
    public function actionPreview(int $sendoutId): Response
    {
        $sendout = Campaign::$plugin->sendouts->getSendoutById($sendoutId);

        if ($sendout === null) {
            throw new BadRequestHttpException('Invalid sendout ID: ' . $sendoutId);
        }

        $this->view->registerAssetBundle(SendoutPreflightAsset::class);
        $this->view->registerAssetBundle(SendTestAsset::class);

        $campaign = $sendout->getCampaign();
        $campaignType = $campaign ? $campaign->getCampaignType() : null;

        $variables = [
            'sendout' => $sendout,
            'schedule' => $sendout->getSchedule(),
            'settings' => Campaign::$plugin->settings,
            'contactElementType' => ContactElement::class,
            'testContactCriteria' => ['status' => ContactElement::STATUS_ACTIVE],
            'testContacts' => $campaignType ? $campaignType->getTestContactsWithDefault() : [],
            'actions' => [],
            'isDynamicWebAliasUsed' => SettingsHelper::isDynamicWebAliasUsed($sendout->siteId),
            'getHtmlBodyActionUrl' => UrlHelper::actionUrl('campaign/sendouts/get-html-body'),
            'sendTestActionUrl' => UrlHelper::actionUrl('campaign/sendouts/send-test'),
        ];

        if ($sendout->getIsPausable()) {
            $variables['actions'][0][] = [
                'action' => 'campaign/sendouts/pause',
                'redirect' => $sendout->getCpEditUrl(),
                'label' => Craft::t('campaign', 'Pause and Edit'),
                'confirm' => Craft::t('campaign', 'Are you sure you want to pause this sendout?'),
            ];
        }

        if ($sendout->getIsCancellable()) {
            $variables['actions'][1][] = [
                'action' => 'campaign/sendouts/cancel',
                'destructive' => 'true',
                'redirect' => $sendout->getCpEditUrl(),
                'label' => Craft::t('campaign', 'Cancel'),
                'confirm' => Craft::t('campaign', 'Are you sure you want to cancel this sendout? It cannot be sent again if cancelled.'),
            ];
        }

        $sendoutField = new SendoutFieldLayoutElement();
        $sendoutField->formHtml($sendout);

        return $this->renderTemplate('campaign/sendouts/_preview', $variables);
    }

    /**
     * Sends the sendout.
     */
    public function actionSend(): ?Response
    {
        $this->requirePermission('campaign:sendSendouts');
        $this->requirePostRequest();

        $sendout = $this->getSendoutFromParamId();
        $sendout->senderId = Craft::$app->getUser()->getId();
        $sendout->sendStatus = SendoutElement::STATUS_PENDING;

        if (!Craft::$app->getElements()->saveElement($sendout)) {
            return $this->asModelFailure($sendout, Craft::t('campaign', 'Couldn’t save sendout.'), 'sendout');
        }

        Campaign::$plugin->log('Sendout “{title}” initiated by “{username}”.', [
            'title' => $sendout->title,
        ]);

        Campaign::$plugin->sendouts->queuePendingSendouts();

        return $this->asModelSuccess($sendout, Craft::t('campaign', 'Sendout saved.'), 'sendout');
    }

    /**
     * Sends a test.
     */
    public function actionSendTest(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $contactIds = $this->request->getBodyParam('contactIds');
        $sendout = $this->getSendoutFromParamId();

        // Validate test contacts
        if (empty($contactIds)) {
            return $this->asFailure(Craft::t('campaign', 'At least one contact must be selected.'));
        }

        $contacts = Campaign::$plugin->contacts->getContactsByIds($contactIds);

        foreach ($contacts as $contact) {
            if (!Campaign::$plugin->sendouts->sendTest($sendout, $contact)) {
                return $this->asFailure(Craft::t('campaign', 'Couldn’t send test email.'));
            }
        }

        return $this->asSuccess(Craft::t('campaign', 'Test email sent.'));
    }

    /**
     * Pauses a sendout.
     */
    public function actionPause(): ?Response
    {
        $this->requirePostRequest();

        $sendout = $this->getSendoutFromParamId();

        if (!Campaign::$plugin->sendouts->pauseSendout($sendout)) {
            return $this->asFailure(Craft::t('campaign', 'Sendout could not be paused.'));
        }

        Campaign::$plugin->log('Sendout “{title}” paused by “{username}”.', [
            'title' => $sendout->title,
        ]);

        return $this->asSuccess(Craft::t('campaign', 'Sendout paused.'));
    }

    /**
     * Cancels a sendout.
     */
    public function actionCancel(): ?Response
    {
        $this->requirePostRequest();

        $sendout = $this->getSendoutFromParamId();

        if (!Campaign::$plugin->sendouts->cancelSendout($sendout)) {
            return $this->asFailure(Craft::t('campaign', 'Sendout could not be cancelled.'));
        }

        Campaign::$plugin->log('Sendout “{title}” cancelled by “{username}”.', [
            'title' => $sendout->title,
        ]);

        return $this->asSuccess(Craft::t('campaign', 'Sendout cancelled.'));
    }

    /**
     * Gets a sendout from a param ID.
     */
    private function getSendoutFromParamId(): SendoutElement
    {
        $sendoutId = $this->request->getRequiredParam('sendoutId');
        $sendout = Campaign::$plugin->sendouts->getSendoutById($sendoutId);

        if ($sendout === null) {
            throw new NotFoundHttpException(Craft::t('campaign', 'Sendout not found.'));
        }

        return $sendout;
    }
}
