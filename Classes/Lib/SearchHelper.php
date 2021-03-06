<?php

namespace TeaminmediasPluswerk\KeSearch\Lib;

/***************************************************************
 *  Copyright notice
 *  (c) 2014 Christian Bülter (kennziffer.com) <buelter@kennziffer.com>
 *  All rights reserved
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use PDO;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;

/**
 * helper functions
 * must be used used statically!
 * Example:
 * $this->extConf = tx_kesearch_helper::getExtConf();
 */
class SearchHelper
{
    public static $systemCategoryPrefix = 'syscat';

    /**
     * get extension manager configuration for ke_search
     * and make it possible to override it with page ts setup
     * @return array
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    public static function getExtConf()
    {
        /** @var ExtensionConfiguration $extensionConfigurationApi */
        $extensionConfigurationApi = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $extConf = $extensionConfigurationApi->get('ke_search');

        // Set the "tagChar"
        // sphinx has problems with # in query string.
        // so you we need to change the default char # against something else.
        // MySQL has problems also with #
        // but we wrap # with " and it works.
        $keSearchPremiumIsLoaded = ExtensionManagementUtility::isLoaded('ke_search_premium');
        if ($keSearchPremiumIsLoaded) {
            $extConfPremium = SearchHelper::getExtConfPremium();
            $extConf['prePostTagChar'] = $extConfPremium['prePostTagChar'];
        } else {
            $extConf['prePostTagChar'] = '#';
        }
        $extConf['multiplyValueToTitle'] = ($extConf['multiplyValueToTitle']) ? $extConf['multiplyValueToTitle'] : 1;
        $extConf['searchWordLength'] = ($extConf['searchWordLength']) ? $extConf['searchWordLength'] : 4;

        // override extConf with TS Setup
        if (is_array($GLOBALS['TSFE']->tmpl->setup['ke_search.']['extconf.']['override.'])
            && count($GLOBALS['TSFE']->tmpl->setup['ke_search.']['extconf.']['override.'])) {
            foreach ($GLOBALS['TSFE']->tmpl->setup['ke_search.']['extconf.']['override.'] as $key => $value) {
                $extConf[$key] = $value;
            }
        }

        return $extConf;
    }

    /**
     * get extension manager configuration for ke_search_premium
     * and make it possible to override it with page ts setup
     * @return array
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    public static function getExtConfPremium()
    {
        /** @var ExtensionConfiguration $extensionConfigurationApi */
        $extensionConfigurationApi = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $keSearchPremiumIsLoaded = ExtensionManagementUtility::isLoaded('ke_search_premium');
        if ($keSearchPremiumIsLoaded) {
            $extConfPremium = $extensionConfigurationApi->get('ke_search_premium');
            if (!$extConfPremium['prePostTagChar']) {
                $extConfPremium['prePostTagChar'] = '_';
            }
        } else {
            $extConfPremium = array();
        }

        // override extConfPremium with TS Setup
        if (is_array($GLOBALS['TSFE']->tmpl->setup['ke_search_premium.']['extconf.']['override.'])
            && count($GLOBALS['TSFE']->tmpl->setup['ke_search_premium.']['extconf.']['override.'])) {
            foreach ($GLOBALS['TSFE']->tmpl->setup['ke_search_premium.']['extconf.']['override.'] as $key => $value) {
                $extConfPremium[$key] = $value;
            }
        }

        return $extConfPremium;
    }

    /**
     * returns the list of assigned categories to a certain record in a certain table
     * @param int $uid
     * @param string $table
     * @return array
     */
    public static function getCategories(int $uid, string $table)
    {
        $categoryData = array(
            'uid_list' => array(),
            'title_list' => array()
        );

        if ($uid && $table) {
            $queryBuilder = Db::getQueryBuilder($table);

            $categoryRecords = $queryBuilder
                ->select('sys_category.uid', 'sys_category.title')
                ->from('sys_category')
                ->from('sys_category_record_mm')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq(
                        'sys_category.uid',
                        $queryBuilder->quoteIdentifier(
                            'sys_category_record_mm.uid_local'
                        )
                    ),
                    $queryBuilder->expr()->eq(
                        $table . '.uid',
                        $queryBuilder->quoteIdentifier(
                            'sys_category_record_mm.uid_foreign'
                        )
                    ),
                    $queryBuilder->expr()->eq(
                        $table . '.uid',
                        $queryBuilder->createNamedParameter(
                            $uid,
                            PDO::PARAM_INT
                        )
                    ),
                    $queryBuilder->expr()->eq(
                        'sys_category_record_mm.tablenames',
                        $queryBuilder->quote($table, PDO::PARAM_STR)
                    )
                )
                ->orderBy('sys_category_record_mm.sorting')
                ->execute()
                ->fetchAll();

            if (!empty($categoryRecords)) {
                foreach ($categoryRecords as $cat) {
                    $categoryData['uid_list'][] = $cat['uid'];
                    $categoryData['title_list'][] = $cat['title'];
                }
            }
        }

        return $categoryData;
    }

    /**
     * Adds a tag to a given list of comma-separated tags.
     * Does not add the tag if it is already in the list.
     *
     * @param string $tagToAdd Tag without the "prePostTagChar" (normally #)
     * @param string $tags
     * @return string
     */
    public static function addTag(string $tagToAdd, $tags='')
    {
        if ($tagToAdd) {
            $extConf = SearchHelper::getExtConf();
            $tagToAdd = $extConf['prePostTagChar'] . $tagToAdd . $extConf['prePostTagChar'];
            $tagArray = GeneralUtility::trimExplode(',', $tags);
            if (!in_array($tagToAdd, $tagArray)) {
                if (strlen($tags)) {
                    $tags .= ',';
                }
                $tags .= $tagToAdd;
            }
        }
        return $tags;
    }

    /**
     * creates tags from category titles
     * removes characters: # , space ( ) _
     * @param string|null $tags comma-list of tags, new tags will be added to this
     * @param array $categoryArray Array of Titles (eg. categories)
     */
    public static function makeTags(&$tags, array $categoryArray)
    {
        if (is_array($categoryArray) && count($categoryArray)) {
            $tags = $tags ?? '';
            $extConf = SearchHelper::getExtConf();

            foreach ($categoryArray as $catTitle) {
                $tag = $catTitle;
                $tag = str_replace('#', '', $tag);
                $tag = str_replace(',', '', $tag);
                $tag = str_replace(' ', '', $tag);
                $tag = str_replace('(', '', $tag);
                $tag = str_replace(')', '', $tag);
                $tag = str_replace('_', '', $tag);
                $tag = str_replace('&', '', $tag);

                if (!empty($tags)) {
                    $tags .= ',';
                }
                $tags .= $extConf['prePostTagChar'] . $tag . $extConf['prePostTagChar'];
            }
        }
    }

    /**
     * finds the system categories for $uid in $tablename, creates
     * tags like "syscat123" ("syscat" + category uid).
     *
     * @param string|null $tags
     * @param int $uid
     * @param string $tablename
     */
    public static function makeSystemCategoryTags(&$tags, int $uid, string $tablename)
    {
        $tags = $tags ?? '';
        $categories = SearchHelper::getCategories($uid, $tablename);
        if (count($categories['uid_list'])) {
            foreach ($categories['uid_list'] as $category_uid) {
                SearchHelper::makeTags($tags, array(SearchHelper::createTagnameFromSystemCategoryUid($category_uid)));
            }
        }
    }

    /**
     * creates tags like "syscat123" ("syscat" + category uid).
     *
     * @param int $uid
     * @return string
     */
    public static function createTagnameFromSystemCategoryUid(int $uid)
    {
        return SearchHelper::$systemCategoryPrefix . $uid;
    }

    /**
     * renders a link to a search result
     *
     * @param array $resultRow
     * @param string $targetDefault
     * @param string $targetFiles
     * @return array
     */
    public static function getResultLinkConfiguration(array $resultRow, $targetDefault = '', $targetFiles = '')
    {
        $linkConf = array();

        list($type) = explode(':', $resultRow['type']);

        switch ($type) {
            case 'file':
                // render a link for files
                // if we use FAL, we can use the API
                if ($resultRow['orig_uid'] && ($fileObject = SearchHelper::getFile($resultRow['orig_uid']))) {
                    $linkConf['parameter'] = 't3://file?uid=' . $resultRow['orig_uid'];
                } else {
                    $linkConf['parameter'] = $resultRow['directory'] . rawurlencode($resultRow['title']);
                }
                $linkConf['fileTarget'] = $targetFiles;
                break;

            case 'external':
                // render a link for external results (provided by eg. ke_search_premium)
                $linkConf['parameter'] = $resultRow['params'];
                $linkConf['useCacheHash'] = false;
                $linkConf['additionalParams'] = '';
                $extConfPremium = SearchHelper::getExtConfPremium();
                $linkConf['extTarget'] = $extConfPremium['apiExternalResultTarget'] ?
                    $extConfPremium['apiExternalResultTarget'] : '_blank';
                break;

            default:
                // render a link for page targets
                // if params are filled, add them to the link generation process
                if (!empty($resultRow['params'])) {
                    $linkConf['additionalParams'] = $resultRow['params'];
                }
                $linkConf['parameter'] = $resultRow['targetpid'];
                $linkConf['useCacheHash'] = true;
                $linkConf['target'] = $targetDefault;
                break;
        }

        if (VersionNumberUtility::convertVersionNumberToInteger(TYPO3_branch) >=
            VersionNumberUtility::convertVersionNumberToInteger('10.0')
        ) {
            // Deprecated: Setting typolink.useCacheHash has no effect anymore
            unset($linkConf['useCacheHash']);
        }

        return $linkConf;
    }

    /**
     * @param int $uid
     * @return File|NULL
     */
    public static function getFile(int $uid)
    {
        try {
            /** @var ResourceFactory $resourceFactory */
            $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
            $fileObject = $resourceFactory->getFileObject($uid);
        } catch (FileDoesNotExistException $e) {
            $fileObject = null;
        }

        return $fileObject;
    }
}
