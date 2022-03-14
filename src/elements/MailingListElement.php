<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Edit;
use craft\elements\actions\Restore;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\models\UserGroup;
use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\elements\db\MailingListElementQuery;
use putyourlightson\campaign\fieldlayoutelements\mailinglists\MailingListContactFieldLayoutTab;
use putyourlightson\campaign\fieldlayoutelements\reports\MailingListReportFieldLayoutTab;
use putyourlightson\campaign\models\MailingListTypeModel;
use putyourlightson\campaign\records\ContactMailingListRecord;
use putyourlightson\campaign\records\MailingListRecord;
use yii\base\InvalidConfigException;

/**
 * @property-read int $unsubscribedCount
 * @property-read int $complainedCount
 * @property-read ContactElement[] $bouncedContacts
 * @property-read ContactElement[] $unsubscribedContacts
 * @property-read int $bouncedCount
 * @property-read string $reportUrl
 * @property-read null|UserGroup $syncedUserGroup
 * @property-read bool $isEditable
 * @property-read int $subscribedCount
 * @property-read MailingListTypeModel $mailingListType
 * @property-read ContactElement[] $complainedContacts
 * @property-read string[] $cacheTags
 * @property-read null|string $postEditUrl
 * @property-read array[] $crumbs
 * @property-read ContactElement[] $subscribedContacts
 */
class MailingListElement extends Element
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('campaign', 'Mailing List');
    }

    /**
     * @inheritdoc
     */
    public static function lowerDisplayName(): string
    {
        return Craft::t('campaign', 'mailing list');
    }

    /**
     * @inheritdoc
     */
    public static function pluralDisplayName(): string
    {
        return Craft::t('campaign', 'Mailing Lists');
    }

    /**
     * @inheritdoc
     */
    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('campaign', 'mailing lists');
    }

    /**
     * @inheritdoc
     */
    public static function refHandle(): ?string
    {
        return 'mailinglist';
    }

    /**
     * @inheritdoc
     */
    public static function hasContent(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function isLocalized(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasTitles(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function find(): MailingListElementQuery
    {
        return new MailingListElementQuery(static::class);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('campaign', 'All mailing lists'),
                'criteria' => [],
            ],
        ];

        $sources[] = ['heading' => Craft::t('campaign', 'Mailing List Types')];

        $mailingListTypes = Campaign::$plugin->mailingListTypes->getAllMailingListTypes();

        foreach ($mailingListTypes as $mailingListType) {
            $sources[] = [
                'key' => 'mailingListType:' . $mailingListType->id,
                'label' => $mailingListType->name,
                'sites' => [$mailingListType->siteId],
                'data' => [
                    'handle' => $mailingListType->handle,
                ],
                'criteria' => [
                    'mailingListTypeId' => $mailingListType->id,
                ],
            ];
        }

        return $sources;
    }

    /**
     * @inheritdoc
     */
    protected static function defineActions(string $source = null): array
    {
        $actions = [];

        $elementsService = Craft::$app->getElements();

        // Edit
        $actions[] = $elementsService->createAction([
            'type' => Edit::class,
            'label' => Craft::t('campaign', 'Edit mailing list'),
        ]);

        // Delete
        $actions[] = $elementsService->createAction([
            'type' => Delete::class,
            'confirmationMessage' => Craft::t('campaign', 'Are you sure you want to delete the selected mailing lists?'),
            'successMessage' => Craft::t('campaign', 'Mailing lists deleted.'),
        ]);

        // Restore
        $actions[] = $elementsService->createAction([
            'type' => Restore::class,
            'successMessage' => Craft::t('campaign', 'Mailing lists restored.'),
            'partialSuccessMessage' => Craft::t('campaign', 'Some mailing lists restored.'),
            'failMessage' => Craft::t('campaign', 'Mailing lists not restored.'),
        ]);

        return $actions;
    }

    /**
     * @inheritdoc
     */
    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            [
                'label' => Craft::t('app', 'Date Created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
            ],
            [
                'label' => Craft::t('app', 'Date Updated'),
                'orderBy' => 'elements.dateUpdated',
                'attribute' => 'dateUpdated',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('app', 'Title')],
            'mailingListType' => ['label' => Craft::t('campaign', 'Mailing List Type')],
            'subscribed' => ['label' => Craft::t('campaign', 'Subscribed')],
            'unsubscribed' => ['label' => Craft::t('campaign', 'Unsubscribed')],
            'complained' => ['label' => Craft::t('campaign', 'Complained')],
            'bounced' => ['label' => Craft::t('campaign', 'Bounced')],
            'slug' => ['label' => Craft::t('app', 'Slug')],
            'id' => ['label' => Craft::t('app', 'ID')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        $attributes = [];

        if ($source === '*') {
            $attributes[] = 'mailingListType';
        }

        array_push($attributes, 'subscribed', 'unsubscribed', 'complained', 'bounced');

        return $attributes;
    }

    /**
     * @var int|null Mailing list type ID
     */
    public ?int $mailingListTypeId = null;

    /**
     * @var int|null Synced user group ID
     */
    public ?int $syncedUserGroupId = null;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['mailingListTypeId'], 'number', 'integerOnly' => true];

        return $rules;
    }

    /**
     * @inheritdoc
     * @since 2.0.0
     */
    public function getCacheTags(): array
    {
        return [
            "mailingListType:$this->mailingListTypeId",
        ];
    }

    /**
     * @inheritdoc
     */
    public function getSupportedSites(): array
    {
        return [$this->getMailingListType()->siteId];
    }

    /**
     * @inheritdoc
     */
    protected function uiLabel(): ?string
    {
        if (!isset($this->title) || trim($this->title) === '') {
            return Craft::t('campaign', 'Untitled mailing list');
        }

        return null;
    }

    /**
     * @inheritdoc
     * @since 2.0.0
     */
    public function canView(User $user): bool
    {
        if (parent::canView($user)) {
            return true;
        }

        return $this->_canManage($user);
    }

    /**
     * @inheritdoc
     * @since 2.0.0
     */
    public function canSave(User $user): bool
    {
        if (parent::canSave($user)) {
            return true;
        }

        return $this->_canManage($user);
    }

    /**
     * @inheritdoc
     * @since 2.0.0
     */
    public function canDuplicate(User $user): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     * @since 2.0.0
     */
    public function canDelete(User $user): bool
    {
        if (parent::canDelete($user)) {
            return true;
        }

        return $this->_canManage($user);
    }

    /**
     * @inheritdoc
     * @since 2.0.0
     */
    public function canCreateDrafts(User $user): bool
    {
        return true;
    }

    /**
     * Returns the number of subscribed contacts.
     */
    public function getSubscribedCount(): int
    {
        return $this->_getContactCount('subscribed');
    }

    /**
     * Returns the number of unsubscribed contacts.
     */
    public function getUnsubscribedCount(): int
    {
        return $this->_getContactCount('unsubscribed');
    }

    /**
     * Returns the number of complained contacts.
     */
    public function getComplainedCount(): int
    {
        return $this->_getContactCount('complained');
    }

    /**
     * Returns the number of bounced contacts.
     */
    public function getBouncedCount(): int
    {
        return $this->_getContactCount('bounced');
    }

    /**
     * Returns the subscribed contacts.
     *
     * @return ContactElement[]
     */
    public function getSubscribedContacts(): array
    {
        return $this->_getContactsBySubscriptionStatus('subscribed');
    }

    /**
     * Returns the subscribed contacts.
     *
     * @return ContactElement[]
     */
    public function getUnsubscribedContacts(): array
    {
        return $this->_getContactsBySubscriptionStatus('unsubscribed');
    }

    /**
     * Returns the complained contacts.
     *
     * @return ContactElement[]
     */
    public function getComplainedContacts(): array
    {
        return $this->_getContactsBySubscriptionStatus('complained');
    }

    /**
     * Returns the bounced contacts.
     *
     * @return ContactElement[]
     */
    public function getBouncedContacts(): array
    {
        return $this->_getContactsBySubscriptionStatus('bounced');
    }

    /**
     * Returns the mailing list's mailing list type.
     */
    public function getMailingListType(): MailingListTypeModel
    {
        if ($this->mailingListTypeId === null) {
            throw new InvalidConfigException('Mailing list is missing its mailing list type ID');
        }

        $mailingListType = Campaign::$plugin->mailingListTypes->getMailingListTypeById($this->mailingListTypeId);

        if ($mailingListType === null) {
            throw new InvalidConfigException('Invalid mailing list type ID: ' . $this->mailingListTypeId);
        }

        return $mailingListType;
    }

    /**
     * Returns the mailing list's synced user group.
     */
    public function getSyncedUserGroup(): ?UserGroup
    {
        if ($this->syncedUserGroupId === null) {
            return null;
        }

        return Craft::$app->getUserGroups()->getGroupById($this->syncedUserGroupId);
    }

    /**
     * @inheritdoc
     * @since 2.0.0
     */
    protected function cpEditUrl(): ?string
    {
        $mailingListType = $this->getMailingListType();

        $path = sprintf('campaign/mailinglists/%s/%s', $mailingListType->handle, $this->getCanonicalId());

        // Ignore homepage/temp slugs
        if ($this->slug && !str_starts_with($this->slug, '__')) {
            $path .= "-$this->slug";
        }

        return UrlHelper::cpUrl($path);
    }

    /**
     * @inheritdoc
     */
    public function getFieldLayout(): ?FieldLayout
    {
        $fieldLayout = parent::getFieldLayout() ?? $this->getMailingListType()->getFieldLayout();

        $fieldLayout->setTabs(array_merge(
            $fieldLayout->getTabs(),
            [
                new MailingListContactFieldLayoutTab(),
                new MailingListReportFieldLayoutTab(),
            ],
        ));

        return $fieldLayout;
    }

    /**
     * @inheritdoc
     * @since 2.0.0
     */
    protected function metaFieldsHtml(bool $static): string
    {
        return $this->slugFieldHtml($static) . parent::metaFieldsHtml($static);
    }

    /**
     * @inheritdoc
     */
    public function getPostEditUrl(): ?string
    {
        $mailingListType = $this->getMailingListType();

        return UrlHelper::cpUrl("campaign/mailinglists/$mailingListType->handle");
    }

    /**
     * @inheritdoc
     * @since 2.0.0
     */
    public function getCrumbs(): array
    {
        $mailingListType = $this->getMailingListType();

        return [
            [
                'label' => Craft::t('campaign', 'Mailing Lists'),
                'url' => UrlHelper::url('campaign/mailinglists'),
            ],
            [
                'label' => Craft::t('campaign', $mailingListType->name),
                'url' => UrlHelper::url('campaign/mailinglists/' . $mailingListType->handle),
            ],
        ];
    }

    /**
     * Returns the mailing list's report URL.
     */
    public function getReportUrl(): string
    {
        return UrlHelper::cpUrl('campaign/reports/mailinglists/' . $this->id);
    }

    /**
     * @inheritdoc
     */
    protected function tableAttributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'mailingListType' => $this->getMailingListType()->name,
            'subscribed' => (string)$this->getSubscribedCount(),
            'unsubscribed' => (string)$this->getUnsubscribedCount(),
            'complained' => (string)$this->getComplainedCount(),
            'bounced' => (string)$this->getBouncedCount(),
            default => parent::tableAttributeHtml($attribute),
        };
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew): void
    {
        if ($isNew) {
            $mailingListRecord = new MailingListRecord();
            $mailingListRecord->id = $this->id;
        }
        else {
            $mailingListRecord = MailingListRecord::findOne($this->id);
        }

        if ($mailingListRecord) {
            // Set attributes
            $mailingListRecord->mailingListTypeId = $this->mailingListTypeId;
            $mailingListRecord->syncedUserGroupId = $this->syncedUserGroupId;

            $mailingListRecord->save(false);
        }

        parent::afterSave($isNew);
    }

    /**
     * Returns the number of contacts in this mailing list.
     */
    private function _getContactCount(string $subscriptionStatus = null): int
    {
        $subscriptionStatus = $subscriptionStatus ?? '';

        $condition = ['mailingListId' => $this->id];

        if ($subscriptionStatus) {
            $condition['subscriptionStatus'] = $subscriptionStatus;
        }

        return ContactMailingListRecord::find()
            ->where($condition)
            ->count();
    }

    /**
     * Returns the contacts in this mailing list by subscription status.
     *
     * @return ContactElement[]
     */
    private function _getContactsBySubscriptionStatus(string $subscriptionStatus): array
    {
        $contactIds = ContactMailingListRecord::find()
            ->select('contactId')
            ->where([
                'mailingListId' => $this->id,
                'subscriptionStatus' => $subscriptionStatus,
            ])
            ->column();

        return Campaign::$plugin->contacts->getContactsByIds($contactIds);
    }

    /**
     * Returns whether the mailing list can be managed by the user.
     */
    private function _canManage(User $user): bool
    {
        return $user->can('campaign:mailingLists:' . $this->getMailingListType()->uid);
    }
}
