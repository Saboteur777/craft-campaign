<?php
/**
 * @link      https://craftcampaign.com
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\helpers;

use Craft;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\models\CampaignTypeModel;
use putyourlightson\campaign\models\MailingListTypeModel;

/**
 * ProjectConfigDataHelper
 *
 * @author    PutYourLightsOn
 * @package   Campaign
 * @since     1.12.0
 */
class ProjectConfigDataHelper
{
    // Static Methods
    // =========================================================================

    /**
     * Rebuild project config
     *
     * @return array
     */
    public static function rebuildProjectConfig(): array
    {
        $configData = [
            'campaignTypes' => [],
            'mailingListTypes' => [],
        ];

        $campaignTypes = Campaign::$plugin->campaignTypes->getAllCampaignTypes();

        foreach ($campaignTypes as $campaignType) {
            $configData['campaignTypes'][$campaignType->uid] = self::getCampaignTypeData($campaignType);
        }

        $mailingListTypes = Campaign::$plugin->mailingListTypes->getAllMailingListTypes();

        foreach ($mailingListTypes as $mailingListType) {
            $configData['mailingListTypes'][$mailingListType->uid] = self::getMailingListTypeData($mailingListType);
        }

        return $configData;
    }

    /**
     * Returns campaign type data
     *
     * @param CampaignTypeModel $campaignType
     *
     * @return array
     */
    public static function getCampaignTypeData(CampaignTypeModel $campaignType): array
    {
        // Get config data from attributes
        $configData = $campaignType->getAttributes(null, ['id', 'siteId', 'fieldLayoutId', 'uid']);

        // Set the site UID
        $configData['siteUid'] = Db::uidById(Table::SITES, $campaignType->siteId);

        // Set the field layout
        $fieldLayout = $campaignType->getFieldLayout();
        $fieldLayoutConfig = $fieldLayout->getConfig();

        if ($fieldLayoutConfig) {
            if (empty($fieldLayout->id)) {
                $layoutUid = StringHelper::UUID();
                $fieldLayout->uid = $layoutUid;
            }
            else {
                $layoutUid = Db::uidById(Table::FIELDLAYOUTS, $fieldLayout->id);
            }

            $configData['fieldLayouts'] = [$layoutUid => $fieldLayoutConfig];
        }

        return $configData;
    }

    /**
     * Returns mailing list type data
     *
     * @param MailingListTypeModel $mailingListType
     *
     * @return array
     */
    public static function getMailingListTypeData(MailingListTypeModel $mailingListType): array
    {
        // Get config data from attributes
        $configData = $mailingListType->getAttributes(null, ['id', 'siteId', 'fieldLayoutId', 'uid']);

        // Set the site UID
        $configData['siteUid'] = Db::uidById(Table::SITES, $mailingListType->siteId);

        if (!empty($mailingListType['fieldLayoutId'])) {
            $layout = Craft::$app->getFields()->getLayoutById($mailingListType['fieldLayoutId']);

            if ($layout) {
                $configData['fieldLayouts'] = [$layout->uid => $layout->getConfig()];
            }
        }

        unset($configData['uid'], $configData['fieldLayoutId']);

        return $configData;
    }
}
