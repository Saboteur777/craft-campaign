<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\records;

use craft\records\Element;
use craft\records\User;
use DateTime;
use putyourlightson\campaign\base\BaseActiveRecord;
use yii\db\ActiveQuery;

/**
 * ContactRecord
 *
 * @property int $id ID
 * @property int|null $userId User ID
 * @property string $cid Contact ID
 * @property string $email Email
 * @property string $country Country
 * @property string $geoIp GeoIP
 * @property string $device Device
 * @property string $os OS
 * @property string $client Client
 * @property DateTime $lastActivity Last activity
 * @property DateTime $verified Verified
 * @property DateTime $complained Complained
 * @property DateTime $bounced Bounced
 * @property ActiveQuery $element
 * @property ActiveQuery $user
 *
 * @author    PutYourLightsOn
 * @package   Campaign
 * @since     1.0.0
 */
class ContactRecord extends BaseActiveRecord
{
    // Public Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string the table name
     */
    public static function tableName(): string
    {
        return '{{%campaign_contacts}}';
    }

    /**
     * @return ActiveQuery
     */
    public static function find()
    {
        return parent::find()
            ->innerJoinWith(['element element'])
            ->where(['element.dateDeleted' => null]);
    }

    // Public Methods
    // =========================================================================

    /**
     * Returns the related element.
     *
     * @return ActiveQuery
     */
    public function getElement(): ActiveQuery
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }

    /**
     * Returns the related user record.
     *
     * @return ActiveQuery
     */
    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }
}
