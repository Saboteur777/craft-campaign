<?php
/**
 * @link      https://craftcampaign.com
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\services;

use craft\errors\MissingComponentException;
use craft\helpers\ConfigHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\mail\Message;
use craft\web\View;
use DateTime;
use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\elements\ContactElement;
use putyourlightson\campaign\elements\MailingListElement;
use putyourlightson\campaign\events\SubscribeContactEvent;
use putyourlightson\campaign\events\UnsubscribeContactEvent;
use putyourlightson\campaign\events\UpdateContactEvent;
use putyourlightson\campaign\helpers\ContactActivityHelper;
use putyourlightson\campaign\models\PendingContactModel;

use Craft;
use craft\base\Component;
use craft\errors\ElementNotFoundException;
use putyourlightson\campaign\records\PendingContactRecord;
use Twig\Error\Error;
use yii\base\Exception;
use yii\helpers\Json;

/**
 * FormsService
 *
 * @author    PutYourLightsOn
 * @package   Campaign
 * @since     1.10.0
 */
class FormsService extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event SubscribeContactEvent
     */
    const EVENT_BEFORE_SUBSCRIBE_CONTACT = 'beforeSubscribeContact';

    /**
     * @event SubscribeContactEvent
     */
    const EVENT_AFTER_SUBSCRIBE_CONTACT = 'afterSubscribeContact';

    /**
     * @event UnsubscribeContactEvent
     */
    const EVENT_BEFORE_UNSUBSCRIBE_CONTACT = 'beforeUnsubscribeContact';

    /**
     * @event UnsubscribeContactEvent
     */
    const EVENT_AFTER_UNSUBSCRIBE_CONTACT = 'afterUnsubscribeContact';

    /**
     * @event UpdateContactEvent
     */
    const EVENT_BEFORE_UPDATE_CONTACT = 'beforeUpdateContact';

    /**
     * @event UpdateContactEvent
     */
    const EVENT_AFTER_UPDATE_CONTACT = 'afterUpdateContact';

    // Public Methods
    // =========================================================================

    /**
     * Saves a pending contact
     *
     * @param PendingContactModel $pendingContact
     *
     * @return bool
     */
    public function savePendingContact(PendingContactModel $pendingContact): bool
    {
        $this->purgeExpiredPendingContacts();

        $settings = Campaign::$plugin->getSettings();

        $condition = [
            'email' => $pendingContact->email,
            'mailingListId' => $pendingContact->mailingListId,
        ];

        // Check if max pending contacts reached for this email
        $numPendingContactRecords = PendingContactRecord::find()
            ->where($condition)
            ->count();

        if ($numPendingContactRecords >= $settings->maxPendingContacts) {
            // Delete oldest pending contacts
            $pendingContactRecords = PendingContactRecord::find()
                ->where($condition)
                ->orderBy(['dateCreated' => SORT_ASC])
                ->limit($numPendingContactRecords - $settings->maxPendingContacts + 1)
                ->all();

            foreach ($pendingContactRecords as $pendingContactRecord) {
                $pendingContactRecord->delete();
            }
        }

        $pendingContactRecord = new PendingContactRecord();

        $pendingContactRecord->setAttributes($pendingContact->getAttributes(), false);

        return $pendingContactRecord->save();
    }

    /**
     * Verifies a pending contact
     *
     * @param string $pid
     *
     * @return PendingContactModel|null
     */
    public function verifyPendingContact(string $pid)
    {
        // Get pending contact
        $pendingContactRecord = PendingContactRecord::find()
            ->where(['pid' => $pid])
            ->one();

        if ($pendingContactRecord === null) {
            return null;
        }

        /** @var PendingContactModel $pendingContact */
        $pendingContact = PendingContactModel::populateModel($pendingContactRecord, false);

        // Get contact if it exists
        $contact = Campaign::$plugin->contacts->getContactByEmail($pendingContact->email);

        if ($contact === null) {
            // Get trashed contact
            $contact = Campaign::$plugin->contacts->getContactByEmail($pendingContact->email, true);

            // If no contact found or trashed contact could not be restored
            if ($contact === null || !Craft::$app->getElements()->restoreElement($contact)) {
                $contact = new ContactElement();
            }
        }

        $contact->verified = new DateTime();

        $contact->email = $pendingContact->email;

        // Set field values
        $contact->fieldLayoutId = Campaign::$plugin->getSettings()->contactFieldLayoutId;
        $contact->setFieldValues(Json::decode($pendingContact->fieldData));

        if (!Craft::$app->getElements()->saveElement($contact)) {
            return null;
        };

        // Delete pending contact
        $pendingContactRecord = PendingContactRecord::find()
            ->where(['pid' => $pendingContact->pid])
            ->one();

        if ($pendingContactRecord !== null) {
            $pendingContactRecord->delete();
        }

        return $pendingContact;
    }

    /**
     * Deletes expired pending contacts
     */
    public function purgeExpiredPendingContacts()
    {
        $settings = Campaign::$plugin->getSettings();

        if ($settings->purgePendingContactsDuration === 0) {
            return;
        }

        $purgePendingContactsDuration = ConfigHelper::durationInSeconds($settings->purgePendingContactsDuration);
        $interval = DateTimeHelper::secondsToInterval($purgePendingContactsDuration);
        $expire = DateTimeHelper::currentUTCDateTime();
        $pastTime = $expire->sub($interval);

        $pendingContactRecords = PendingContactRecord::find()
            ->where(['<', 'dateUpdated', Db::prepareDateForDb($pastTime)])
            ->all();

        foreach ($pendingContactRecords as $pendingContactRecord) {
            $pendingContactRecord->delete();

            /** @var PendingContactRecord $pendingContactRecord */
            Campaign::$plugin->log('Deleted pending contact "{email}", because they took too long to verify their email.', ['email' => $pendingContactRecord->email]);
        }
    }

    /**
     * Sends a verify subscribe email
     *
     * @param PendingContactModel $pendingContact
     * @param MailingListElement $mailingList
     *
     * @return bool
     * @throws Exception
     * @throws MissingComponentException
     */
    public function sendVerifySubscribeEmail(PendingContactModel $pendingContact, MailingListElement $mailingList): bool
    {
        // Set the current site from the mailing list's site ID
        Craft::$app->sites->setCurrentSite($mailingList->siteId);

        $path = Craft::$app->getConfig()->getGeneral()->actionTrigger.'/campaign/forms/verify-subscribe';
        $url = UrlHelper::siteUrl($path, ['pid' => $pendingContact->pid]);

        $subject = Craft::t('campaign', 'Verify your email address');
        $bodyText = Craft::t('campaign', 'Thank you for subscribing to the mailing list. Please verify your email address by clicking on the following link:');
        $body = $bodyText."\n".$url;

        // Get subject from setting if defined
        $subject = $mailingList->mailingListType->subscribeVerificationEmailSubject ?: $subject;

        // Get body from template if defined
        if ($mailingList->mailingListType->subscribeVerificationEmailTemplate) {
            $view = Craft::$app->getView();

            // Set template mode to site
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

            try {
                $body = $view->renderTemplate($mailingList->mailingListType->subscribeVerificationEmailTemplate, [
                    'message' => $bodyText,
                    'url' => $url,
                    'mailingList' => $mailingList,
                    'pendingContact' => $pendingContact,
                ]);
            }
            catch (Error $e) {}
        }

        return $this->_sendEmail($pendingContact->email, $subject, $body, $mailingList->siteId);
    }

    /**
     * Sends a verify unsubscribe email
     *
     * @param ContactElement $contact
     * @param MailingListElement $mailingList
     *
     * @return bool
     * @throws Exception
     * @throws MissingComponentException
     */
    public function sendVerifyUnsubscribeEmail(ContactElement $contact, MailingListElement $mailingList): bool
    {
        // Set the current site from the mailing list's site ID
        Craft::$app->sites->setCurrentSite($mailingList->siteId);

        $path = Craft::$app->getConfig()->getGeneral()->actionTrigger.'/campaign/forms/verify-unsubscribe';
        $url = UrlHelper::siteUrl($path, [
            'cid' => $contact->cid,
            'uid' => $contact->uid,
            'mlid' => $mailingList->id,
        ]);

        $subject = Craft::t('campaign', 'Verify unsubscribe');
        $bodyText = Craft::t('campaign', 'Please verify that you would like to unsubscribe from the mailing list by clicking on the following link:');
        $body = $bodyText."\n".$url;

        // Get subject from setting if defined
        $subject = $mailingList->mailingListType->unsubscribeVerificationEmailSubject ?: $subject;

        // Get body from template if defined
        if ($mailingList->mailingListType->unsubscribeVerificationEmailTemplate) {
            $view = Craft::$app->getView();

            // Set template mode to site
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

            try {
                $body = $view->renderTemplate($mailingList->mailingListType->unsubscribeVerificationEmailTemplate, [
                    'message' => $bodyText,
                    'url' => $url,
                    'mailingList' => $mailingList,
                    'contact' => $contact,
                ]);
            }
            catch (Error $e) {}
        }

        return $this->_sendEmail($contact->email, $subject, $body, $mailingList->siteId);
    }

    /**
     * Subscribe contact
     *
     * @param ContactElement $contact
     * @param MailingListElement $mailingList
     * @param string|null $sourceType
     * @param string|null $source
     * @param bool|null $verify
     *
     * @throws ElementNotFoundException
     * @throws Exception
     */
    public function subscribeContact(ContactElement $contact, MailingListElement $mailingList, string $sourceType = null, string $source = null, bool $verify = null)
    {
        $sourceType = $sourceType ?? '';
        $source = $source ?? '';
        $verify = $verify ?? false;

        // Fire a before event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SUBSCRIBE_CONTACT)) {
            $this->trigger(self::EVENT_BEFORE_SUBSCRIBE_CONTACT, new SubscribeContactEvent([
                'contact' => $contact,
                'mailingList' => $mailingList,
                'sourceType' => $sourceType,
                'source' => $source,
            ]));
        }

        Campaign::$plugin->mailingLists->addContactInteraction($contact, $mailingList, 'subscribed', $sourceType, $source, $verify);

        // Update contact activity
        ContactActivityHelper::updateContactActivity($contact);

        // Fire an after event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SUBSCRIBE_CONTACT)) {
            $this->trigger(self::EVENT_AFTER_SUBSCRIBE_CONTACT, new SubscribeContactEvent([
                'contact' => $contact,
                'mailingList' => $mailingList,
                'sourceType' => $sourceType,
                'source' => $source,
            ]));
        }
    }

    /**
     * Unsubscribes a contact
     *
     * @param ContactElement $contact
     * @param MailingListElement $mailingList
     *
     * @throws ElementNotFoundException
     * @throws Exception
     */
    public function unsubscribeContact(ContactElement $contact, MailingListElement $mailingList)
    {
        // Fire a before event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_UNSUBSCRIBE_CONTACT)) {
            $this->trigger(self::EVENT_BEFORE_UNSUBSCRIBE_CONTACT, new UnsubscribeContactEvent([
                'contact' => $contact,
                'mailingList' => $mailingList,
            ]));
        }

        Campaign::$plugin->mailingLists->addContactInteraction($contact, $mailingList, 'unsubscribed');

        // Fire an after event
        if ($mailingList !== null AND $this->hasEventHandlers(self::EVENT_AFTER_UNSUBSCRIBE_CONTACT)) {
            $this->trigger(self::EVENT_AFTER_UNSUBSCRIBE_CONTACT, new UnsubscribeContactEvent([
                'contact' => $contact,
                'mailingList' => $mailingList,
            ]));
        }

        // Update contact activity
        ContactActivityHelper::updateContactActivity($contact);
    }

    /**
     * Updates a contact
     *
     * @param ContactElement $contact
     *
     * @return bool
     */
    public function updateContact(ContactElement $contact): bool
    {
        // Fire a before event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_UPDATE_CONTACT)) {
            $this->trigger(self::EVENT_BEFORE_UPDATE_CONTACT, new UpdateContactEvent([
                'contact' => $contact,
            ]));
        }

        if (!Craft::$app->getElements()->saveElement($contact)) {
            return false;
        }

        // Update contact activity
        ContactActivityHelper::updateContactActivity($contact);

        // Fire an after event
        if ($this->hasEventHandlers(self::EVENT_AFTER_UPDATE_CONTACT)) {
            $this->trigger(self::EVENT_AFTER_UPDATE_CONTACT, new UpdateContactEvent([
                'contact' => $contact,
            ]));
        }

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Sends an email to a contact
     *
     * @param string $email
     * @param string $subject
     * @param string $body
     * @param int $siteId
     *
     * @return bool
     * @throws MissingComponentException
     */
    public function _sendEmail(string $email, string $subject, string $body, int $siteId): bool
    {
        $mailer = Campaign::$plugin->createMailer();

        // Get from name and email
        $fromNameEmail = Campaign::$plugin->settings->getFromNameEmail($siteId);

        // Create message
        /** @var Message $message */
        $message = $mailer->compose()
            ->setFrom([$fromNameEmail['email'] => $fromNameEmail['name']])
            ->setTo($email)
            ->setSubject($subject)
            ->setHtmlBody($body)
            ->setTextBody($body);

        if ($fromNameEmail['replyTo']) {
            $message->setReplyTo($fromNameEmail['replyTo']);
        }

        return $message->send();
    }
}