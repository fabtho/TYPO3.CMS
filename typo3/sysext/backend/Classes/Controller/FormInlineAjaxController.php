<?php
namespace TYPO3\CMS\Backend\Controller;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\InlineParentRecord;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\Form\InlineStackProcessor;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Backend\Form\Utility\FormEngineUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Handle FormEngine inline ajax calls
 */
class FormInlineAjaxController
{
    /**
     * Create a new inline child via AJAX.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function createAction(ServerRequestInterface $request, ResponseInterface $response)
    {
        $ajaxArguments = isset($request->getParsedBody()['ajax']) ? $request->getParsedBody()['ajax'] : $request->getQueryParams()['ajax'];

        $domObjectId = $ajaxArguments[0];
        $inlineFirstPid = $this->getInlineFirstPidFromDomObjectId($domObjectId);
        $childChildUid = null;
        if (isset($ajaxArguments[1]) && MathUtility::canBeInterpretedAsInteger($ajaxArguments[1])) {
            $childChildUid = (int)$ajaxArguments[1];
        }

        // Parse the DOM identifier, add the levels to the structure stack
        /** @var InlineStackProcessor $inlineStackProcessor */
        $inlineStackProcessor = GeneralUtility::makeInstance(InlineStackProcessor::class);
        $inlineStackProcessor->initializeByParsingDomObjectIdString($domObjectId);
        $inlineStackProcessor->injectAjaxConfiguration($ajaxArguments['context']);

        // Parent, this table embeds the child table
        $parent = $inlineStackProcessor->getStructureLevel(-1);
        $parentFieldName = $parent['field'];

        if (MathUtility::canBeInterpretedAsInteger($parent['uid'])) {
            $command = 'edit';
            $vanillaUid = (int)$parent['uid'];
            $databaseRow = [
                // TcaInlineExpandCollapseState needs the record uid
                'uid' => (int)$parent['uid'],
            ];
        } else {
            $command = 'new';
            $databaseRow = [];
            $vanillaUid = (int)$inlineFirstPid;
        }
        $formDataCompilerInputForParent = [
            'vanillaUid' => $vanillaUid,
            'command' => $command,
            'tableName' => $parent['table'],
            'databaseRow' => $databaseRow,
            'inlineFirstPid' => $inlineFirstPid,
            'columnsToProcess' => [
                $parentFieldName
            ],
            // @todo: still needed?
            'inlineStructure' => $inlineStackProcessor->getStructure(),
            // Do not resolve existing children, we don't need them now
            'inlineResolveExistingChildren' => false,
        ];
        /** @var TcaDatabaseRecord $formDataGroup */
        $formDataGroup = GeneralUtility::makeInstance(InlineParentRecord::class);
        /** @var FormDataCompiler $formDataCompiler */
        $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class, $formDataGroup);
        $parentData = $formDataCompiler->compile($formDataCompilerInputForParent);
        $parentConfig = $parentData['processedTca']['columns'][$parentFieldName]['config'];

        // Child, a record from this table should be rendered
        $child = $inlineStackProcessor->getUnstableStructure();
        if (MathUtility::canBeInterpretedAsInteger($child['uid'])) {
            // If uid comes in, it is the id of the record neighbor record "create after"
            $childVanillaUid = -1 * abs((int)$child['uid']);
        } else {
            // Else inline first Pid is the storage pid of new inline records
            $childVanillaUid = (int)$inlineFirstPid;
        }

        $childTableName = $parentConfig['foreign_table'];
        /** @var TcaDatabaseRecord $formDataGroup */
        $formDataGroup = GeneralUtility::makeInstance(TcaDatabaseRecord::class);
        /** @var FormDataCompiler $formDataCompiler */
        $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class, $formDataGroup);
        $formDataCompilerInput = [
            'command' => 'new',
            'tableName' => $childTableName,
            'vanillaUid' => $childVanillaUid,
            'inlineFirstPid' => $inlineFirstPid,
            'inlineParentConfig' => $parentConfig,
        ];
        if ($childChildUid) {
            $formDataCompilerInput['inlineChildChildUid'] = $childChildUid;
        }
        $childData = $formDataCompiler->compile($formDataCompilerInput);

        // Set language of new child record to the language of the parent record:
        // @todo: To my understanding, the below case can't happen: With localizationMode select, lang overlays
        // @todo: of children are only created with the "synchronize" button that will trigger a different ajax action.
        // @todo: The edge case of new page overlay together with localized media field, this code won't kick in either.
        /**
        if ($parent['localizationMode'] === 'select' && MathUtility::canBeInterpretedAsInteger($parent['uid'])) {
            $parentRecord = $inlineRelatedRecordResolver->getRecord($parent['table'], $parent['uid']);
            $parentLanguageField = $GLOBALS['TCA'][$parent['table']]['ctrl']['languageField'];
            $childLanguageField = $GLOBALS['TCA'][$child['table']]['ctrl']['languageField'];
            if ($parentRecord[$parentLanguageField] > 0) {
                $record[$childLanguageField] = $parentRecord[$parentLanguageField];
            }
        }
         */

        if ($parentConfig['foreign_selector'] && $parentConfig['appearance']['useCombination']) {
            // We have a foreign_selector. So, we just created a new record on an intermediate table in $mainChild.
            // Now, if a valid id is given as second ajax parameter, the intermediate row should be connected to an
            // existing record of the child-child table specified by the given uid. If there is no such id, user
            // clicked on "created new" and a new child-child should be created, too.
            if ($childChildUid) {
                // Fetch existing child child
                $childData['databaseRow'][$parentConfig['foreign_selector']] = [
                    $childChildUid,
                ];
                $childData['combinationChild'] = $this->compileCombinationChild($childData, $parentConfig);
            } else {
                /** @var TcaDatabaseRecord $formDataGroup */
                $formDataGroup = GeneralUtility::makeInstance(TcaDatabaseRecord::class);
                /** @var FormDataCompiler $formDataCompiler */
                $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class, $formDataGroup);
                $formDataCompilerInput = [
                    'command' => 'new',
                    'tableName' => $childData['processedTca']['columns'][$parentConfig['foreign_selector']]['config']['foreign_table'],
                    'vanillaUid' => (int)$inlineFirstPid,
                    'inlineFirstPid' => (int)$inlineFirstPid,
                ];
                $childData['combinationChild'] = $formDataCompiler->compile($formDataCompilerInput);
            }
        }

        $childData['inlineParentUid'] = (int)$parent['uid'];
        // @todo: needed?
        $childData['inlineStructure'] = $inlineStackProcessor->getStructure();
        // @todo: needed?
        $childData['inlineExpandCollapseStateArray'] = $parentData['inlineExpandCollapseStateArray'];
        $childData['renderType'] = 'inlineRecordContainer';
        $nodeFactory = GeneralUtility::makeInstance(NodeFactory::class);
        $childResult = $nodeFactory->create($childData)->render();

        $jsonArray = [
            'data' => '',
            'stylesheetFiles' => [],
            'scriptCall' => [],
        ];

        // The HTML-object-id's prefix of the dynamically created record
        $objectName = $inlineStackProcessor->getCurrentStructureDomObjectIdPrefix($inlineFirstPid);
        $objectPrefix = $objectName . '-' . $child['table'];
        $objectId = $objectPrefix . '-' . $childData['databaseRow']['uid'];
        $expandSingle = $parentConfig['appearance']['expandSingle'];
        if (!$child['uid']) {
            $jsonArray['scriptCall'][] = 'inline.domAddNewRecord(\'bottom\',' . GeneralUtility::quoteJSvalue($objectName . '_records') . ',' . GeneralUtility::quoteJSvalue($objectPrefix) . ',json.data);';
            $jsonArray['scriptCall'][] = 'inline.memorizeAddRecord(' . GeneralUtility::quoteJSvalue($objectPrefix) . ',' . GeneralUtility::quoteJSvalue($childData['databaseRow']['uid']) . ',null,' . GeneralUtility::quoteJSvalue($childChildUid) . ');';
        } else {
            $jsonArray['scriptCall'][] = 'inline.domAddNewRecord(\'after\',' . GeneralUtility::quoteJSvalue($domObjectId . '_div') . ',' . GeneralUtility::quoteJSvalue($objectPrefix) . ',json.data);';
            $jsonArray['scriptCall'][] = 'inline.memorizeAddRecord(' . GeneralUtility::quoteJSvalue($objectPrefix) . ',' . GeneralUtility::quoteJSvalue($childData['databaseRow']['uid']) . ',' . GeneralUtility::quoteJSvalue($child['uid']) . ',' . GeneralUtility::quoteJSvalue($childChildUid) . ');';
        }
        $jsonArray = $this->mergeChildResultIntoJsonResult($jsonArray, $childResult);
        if ($parentConfig['appearance']['useSortable']) {
            $inlineObjectName = $inlineStackProcessor->getCurrentStructureDomObjectIdPrefix($inlineFirstPid);
            $jsonArray['scriptCall'][] = 'inline.createDragAndDropSorting(' . GeneralUtility::quoteJSvalue($inlineObjectName . '_records') . ');';
        }
        if (!$parentConfig['appearance']['collapseAll'] && $expandSingle) {
            $jsonArray['scriptCall'][] = 'inline.collapseAllRecords(' . GeneralUtility::quoteJSvalue($objectId) . ',' . GeneralUtility::quoteJSvalue($objectPrefix) . ',' . GeneralUtility::quoteJSvalue($childData['databaseRow']['uid']) . ');';
        }
        // Fade out and fade in the new record in the browser view to catch the user's eye
        $jsonArray['scriptCall'][] = 'inline.fadeOutFadeIn(' . GeneralUtility::quoteJSvalue($objectId . '_div') . ');';

        $response->getBody()->write(json_encode($jsonArray));

        return $response;
    }

    /**
     * Show the details of a child record.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function detailsAction(ServerRequestInterface $request, ResponseInterface $response)
    {
        $ajaxArguments = isset($request->getParsedBody()['ajax']) ? $request->getParsedBody()['ajax'] : $request->getQueryParams()['ajax'];

        $domObjectId = $ajaxArguments[0];
        $inlineFirstPid = $this->getInlineFirstPidFromDomObjectId($domObjectId);

        // Parse the DOM identifier, add the levels to the structure stack
        /** @var InlineStackProcessor $inlineStackProcessor */
        $inlineStackProcessor = GeneralUtility::makeInstance(InlineStackProcessor::class);
        $inlineStackProcessor->initializeByParsingDomObjectIdString($domObjectId);
        $inlineStackProcessor->injectAjaxConfiguration($ajaxArguments['context']);

        // Parent, this table embeds the child table
        $parent = $inlineStackProcessor->getStructureLevel(-1);
        $parentFieldName = $parent['field'];

        $formDataCompilerInputForParent = [
            'vanillaUid' => (int)$parent['uid'],
            'command' => 'edit',
            'tableName' => $parent['table'],
            'databaseRow' => [
                // TcaInlineExpandCollapseState needs this
                'uid' => (int)$parent['uid'],
            ],
            'inlineFirstPid' => $inlineFirstPid,
            'columnsToProcess' => [
                $parentFieldName
            ],
            // @todo: still needed?
            'inlineStructure' => $inlineStackProcessor->getStructure(),
            // Do not resolve existing children, we don't need them now
            'inlineResolveExistingChildren' => false,
        ];
        /** @var TcaDatabaseRecord $formDataGroup */
        $formDataGroup = GeneralUtility::makeInstance(InlineParentRecord::class);
        /** @var FormDataCompiler $formDataCompiler */
        $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class, $formDataGroup);
        $parentData = $formDataCompiler->compile($formDataCompilerInputForParent);
        // Set flag in config so that only the fields are rendered
        // @todo: Solve differently / rename / whatever
        $parentData['processedTca']['columns'][$parentFieldName]['config']['renderFieldsOnly'] = true;
        $parentConfig = $parentData['processedTca']['columns'][$parentFieldName]['config'];

        // Child, a record from this table should be rendered
        $child = $inlineStackProcessor->getUnstableStructure();

        $childData = $this->compileChild($parentData, $parentFieldName, (int)$child['uid']);

        $childData['inlineParentUid'] = (int)$parent['uid'];
        // @todo: needed?
        $childData['inlineStructure'] = $inlineStackProcessor->getStructure();
        // @todo: needed?
        $childData['inlineExpandCollapseStateArray'] = $parentData['inlineExpandCollapseStateArray'];
        $childData['renderType'] = 'inlineRecordContainer';
        $nodeFactory = GeneralUtility::makeInstance(NodeFactory::class);
        $childResult = $nodeFactory->create($childData)->render();

        $jsonArray = [
            'data' => '',
            'stylesheetFiles' => [],
            'scriptCall' => [],
        ];

        // The HTML-object-id's prefix of the dynamically created record
        $objectPrefix = $inlineStackProcessor->getCurrentStructureDomObjectIdPrefix($inlineFirstPid) . '-' . $child['table'];
        $objectId = $objectPrefix . '-' . (int)$child['uid'];
        $expandSingle = $parentConfig['appearance']['expandSingle'];
        $jsonArray['scriptCall'][] = 'inline.domAddRecordDetails(' . GeneralUtility::quoteJSvalue($domObjectId) . ',' . GeneralUtility::quoteJSvalue($objectPrefix) . ',' . ($expandSingle ? '1' : '0') . ',json.data);';
        if ($parentConfig['foreign_unique']) {
            $jsonArray['scriptCall'][] = 'inline.removeUsed(' . GeneralUtility::quoteJSvalue($objectPrefix) . ',\'' . (int)$child['uid'] . '\');';
        }
        $jsonArray = $this->mergeChildResultIntoJsonResult($jsonArray, $childResult);
        if ($parentConfig['appearance']['useSortable']) {
            $inlineObjectName = $inlineStackProcessor->getCurrentStructureDomObjectIdPrefix($inlineFirstPid);
            $jsonArray['scriptCall'][] = 'inline.createDragAndDropSorting(' . GeneralUtility::quoteJSvalue($inlineObjectName . '_records') . ');';
        }
        if (!$parentConfig['appearance']['collapseAll'] && $expandSingle) {
            $jsonArray['scriptCall'][] = 'inline.collapseAllRecords(' . GeneralUtility::quoteJSvalue($objectId) . ',' . GeneralUtility::quoteJSvalue($objectPrefix) . ',\'' . (int)$child['uid'] . '\');';
        }

        $response->getBody()->write(json_encode($jsonArray));

        return $response;
    }

    /**
     * Adds localizations or synchronizes the locations of all child records.
     * Handle AJAX calls to localize all records of a parent, localize a single record or to synchronize with the original language parent.
     *
     * @param ServerRequestInterface $request the incoming request
     * @param ResponseInterface $response the empty response
     * @return ResponseInterface the filled response
     */
    public function synchronizeLocalizeAction(ServerRequestInterface $request, ResponseInterface $response)
    {
        $ajaxArguments = isset($request->getParsedBody()['ajax']) ? $request->getParsedBody()['ajax'] : $request->getQueryParams()['ajax'];
        $domObjectId = $ajaxArguments[0];
        $type = $ajaxArguments[1];

        /** @var InlineStackProcessor $inlineStackProcessor */
        $inlineStackProcessor = GeneralUtility::makeInstance(InlineStackProcessor::class);
        // Parse the DOM identifier (string), add the levels to the structure stack (array), load the TCA config:
        $inlineStackProcessor->initializeByParsingDomObjectIdString($domObjectId);
        $inlineStackProcessor->injectAjaxConfiguration($ajaxArguments['context']);
        $inlineFirstPid = $this->getInlineFirstPidFromDomObjectId($domObjectId);

        $jsonArray = false;
        if ($type === 'localize' || $type === 'synchronize' || MathUtility::canBeInterpretedAsInteger($type)) {
            // Parent, this table embeds the child table
            $parent = $inlineStackProcessor->getStructureLevel(-1);
            $parentFieldName = $parent['field'];

            // Child, a record from this table should be rendered
            $child = $inlineStackProcessor->getUnstableStructure();

            $formDataCompilerInputForParent = [
                'vanillaUid' => (int)$parent['uid'],
                'command' => 'edit',
                'tableName' => $parent['table'],
                'databaseRow' => [
                    // TcaInlineExpandCollapseState needs this
                    'uid' => (int)$parent['uid'],
                ],
                'inlineFirstPid' => $inlineFirstPid,
                'columnsToProcess' => [
                    $parentFieldName
                ],
                // @todo: still needed?
                'inlineStructure' => $inlineStackProcessor->getStructure(),
                // Do not compile existing children, we don't need them now
                'inlineCompileExistingChildren' => false,
            ];
            /** @var TcaDatabaseRecord $formDataGroup */
            $formDataGroup = GeneralUtility::makeInstance(InlineParentRecord::class);
            /** @var FormDataCompiler $formDataCompiler */
            $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class, $formDataGroup);
            $parentData = $formDataCompiler->compile($formDataCompilerInputForParent);
            $parentConfig = $parentData['processedTca']['columns'][$parentFieldName]['config'];
            $oldItemList = $parentData['databaseRow'][$parentFieldName];

            $cmd = array();
            $cmd[$parent['table']][$parent['uid']]['inlineLocalizeSynchronize'] = $parent['field'] . ',' . $type;
            /** @var $tce DataHandler */
            $tce = GeneralUtility::makeInstance(DataHandler::class);
            $tce->stripslashes_values = false;
            $tce->start(array(), $cmd);
            $tce->process_cmdmap();

            $newItemList = $tce->registerDBList[$parent['table']][$parent['uid']][$parentFieldName];

            $jsonArray = array(
                'data' => '',
                'stylesheetFiles' => [],
                'scriptCall' => [],
            );
            $nameObject = $inlineStackProcessor->getCurrentStructureDomObjectIdPrefix($inlineFirstPid);
            $nameObjectForeignTable = $nameObject . '-' . $child['table'];

            $oldItems = $this->getInlineRelatedRecordsUidArray($oldItemList);
            $newItems = $this->getInlineRelatedRecordsUidArray($newItemList);

            // Set the items that should be removed in the forms view:
            $removedItems = array_diff($oldItems, $newItems);
            foreach ($removedItems as $childUid) {
                $jsonArray['scriptCall'][] = 'inline.deleteRecord(' . GeneralUtility::quoteJSvalue($nameObjectForeignTable . '-' . $childUid) . ', {forceDirectRemoval: true});';
            }

            $localizedItems = array_diff($newItems, $oldItems);
            foreach ($localizedItems as $childUid) {
                $childData = $this->compileChild($parentData, $parentFieldName, (int)$childUid);

                $childData['inlineParentUid'] = (int)$parent['uid'];
                // @todo: needed?
                $childData['inlineStructure'] = $inlineStackProcessor->getStructure();
                // @todo: needed?
                $childData['inlineExpandCollapseStateArray'] = $parentData['inlineExpandCollapseStateArray'];
                $childData['renderType'] = 'inlineRecordContainer';
                $nodeFactory = GeneralUtility::makeInstance(NodeFactory::class);
                $childResult = $nodeFactory->create($childData)->render();

                $jsonArray = $this->mergeChildResultIntoJsonResult($jsonArray, $childResult);

                // Get the name of the field used as foreign selector (if any):
                $foreignSelector = isset($parentConfig['foreign_selector']) && $parentConfig['foreign_selector'] ? $parentConfig['foreign_selector'] : false;
                $selectedValue = $foreignSelector ? GeneralUtility::quoteJSvalue($childData['databaseRow'][$foreignSelector]) : 'null';
                if (is_array($selectedValue)) {
                    $selectedValue = $selectedValue[0];
                }
                $jsonArray['scriptCall'][] = 'inline.memorizeAddRecord(' . GeneralUtility::quoteJSvalue($nameObjectForeignTable) . ', ' . GeneralUtility::quoteJSvalue($childUid) . ', null, ' . $selectedValue . ');';
                // Remove possible virtual records in the form which showed that a child records could be localized:
                $transOrigPointerFieldName = $GLOBALS['TCA'][$childData['table']]['ctrl']['transOrigPointerField'];
                if (isset($childData['databaseRow'][$transOrigPointerFieldName]) && $childData['databaseRow'][$transOrigPointerFieldName]) {
                    $transOrigPointerField = $childData['databaseRow'][$transOrigPointerFieldName];
                    if (is_array($transOrigPointerField)) {
                        $transOrigPointerField = $transOrigPointerField[0];
                    }
                    $jsonArray['scriptCall'][] = 'inline.fadeAndRemove(' . GeneralUtility::quoteJSvalue($nameObjectForeignTable . '-' . $transOrigPointerField . '_div') . ');';
                }
                if (!empty($childResult['html'])) {
                    array_unshift($jsonArray['scriptCall'], 'inline.domAddNewRecord(\'bottom\', ' . GeneralUtility::quoteJSvalue($nameObject . '_records') . ', ' . GeneralUtility::quoteJSvalue($nameObjectForeignTable) . ', json.data);');
                }
            }
        }

        $response->getBody()->write(json_encode($jsonArray));

        return $response;
    }

    /**
     * Adds localizations or synchronizes the locations of all child records.
     *
     * @param ServerRequestInterface $request the incoming request
     * @param ResponseInterface $response the empty response
     * @return ResponseInterface the filled response
     */
    public function expandOrCollapseAction(ServerRequestInterface $request, ResponseInterface $response)
    {
        $ajaxArguments = isset($request->getParsedBody()['ajax']) ? $request->getParsedBody()['ajax'] : $request->getQueryParams()['ajax'];
        $domObjectId = $ajaxArguments[0];

        /** @var InlineStackProcessor $inlineStackProcessor */
        $inlineStackProcessor = GeneralUtility::makeInstance(InlineStackProcessor::class);
        // Parse the DOM identifier (string), add the levels to the structure stack (array), don't load TCA config
        $inlineStackProcessor->initializeByParsingDomObjectIdString($domObjectId);
        $expand = $ajaxArguments[1];
        $collapse = $ajaxArguments[2];

        $backendUser = $this->getBackendUserAuthentication();
        // The current table - for this table we should add/import records
        $currentTable = $inlineStackProcessor->getUnstableStructure();
        $currentTable = $currentTable['table'];
        // The top parent table - this table embeds the current table
        $top = $inlineStackProcessor->getStructureLevel(0);
        $topTable = $top['table'];
        $topUid = $top['uid'];
        $inlineView = $this->getInlineExpandCollapseStateArray();
        // Only do some action if the top record and the current record were saved before
        if (MathUtility::canBeInterpretedAsInteger($topUid)) {
            $expandUids = GeneralUtility::trimExplode(',', $expand);
            $collapseUids = GeneralUtility::trimExplode(',', $collapse);
            // Set records to be expanded
            foreach ($expandUids as $uid) {
                $inlineView[$topTable][$topUid][$currentTable][] = $uid;
            }
            // Set records to be collapsed
            foreach ($collapseUids as $uid) {
                $inlineView[$topTable][$topUid][$currentTable] = $this->removeFromArray($uid, $inlineView[$topTable][$topUid][$currentTable]);
            }
            // Save states back to database
            if (is_array($inlineView[$topTable][$topUid][$currentTable])) {
                $inlineView[$topTable][$topUid][$currentTable] = array_unique($inlineView[$topTable][$topUid][$currentTable]);
                $backendUser->uc['inlineView'] = serialize($inlineView);
                $backendUser->writeUC();
            }
        }

        $response->getBody()->write(json_encode(array()));
        return $response;
    }

    /**
     * Compile a full child record
     *
     * @param array $parentData Result array of parent
     * @param string $parentFieldName Name of parent field
     * @param int $childUid Uid of child to compile
     * @return array Full result array
     *
     * @todo: This clones methods compileChild and compileCombinationChild from TcaInline Provider.
     * @todo: Find something around that, eg. some option to force TcaInline provider to calculate a
     * @todo: specific forced-open element only :)
     */
    protected function compileChild(array $parentData, $parentFieldName, $childUid)
    {
        $parentConfig = $parentData['processedTca']['columns'][$parentFieldName]['config'];
        $childTableName = $parentConfig['foreign_table'];
        /** @var TcaDatabaseRecord $formDataGroup */
        $formDataGroup = GeneralUtility::makeInstance(TcaDatabaseRecord::class);
        /** @var FormDataCompiler $formDataCompiler */
        $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class, $formDataGroup);
        $formDataCompilerInput = [
            'command' => 'edit',
            'tableName' => $childTableName,
            'vanillaUid' => (int)$childUid,
            'inlineFirstPid' => $parentData['inlineFirstPid'],
            'inlineParentConfig' => $parentConfig,
        ];
        // For foreign_selector with useCombination $mainChild is the mm record
        // and $combinationChild is the child-child. For "normal" relations, $mainChild
        // is just the normal child record and $combinationChild is empty.
        $mainChild = $formDataCompiler->compile($formDataCompilerInput);
        if ($parentConfig['foreign_selector'] && $parentConfig['appearance']['useCombination']) {
            $mainChild['combinationChild'] = $this->compileCombinationChild($mainChild, $parentConfig);
        }
        return $mainChild;
    }

    /**
     * With useCombination set, not only content of the intermediate table, but also
     * the connected child should be rendered in one go. Prepare this here.
     *
     * @param array $intermediate Full data array of "mm" record
     * @param array $parentConfig TCA configuration of "parent"
     * @return array Full data array of child
     */
    protected function compileCombinationChild(array $intermediate, array $parentConfig)
    {
        // foreign_selector on intermediate is probably type=select, so data provider of this table resolved that to the uid already
        $intermediateUid = $intermediate['databaseRow'][$parentConfig['foreign_selector']][0];
        $combinationChild = $this->compileChild($intermediate, $parentConfig['foreign_selector'], $intermediateUid);
        return $combinationChild;
    }

    /**
     * Merge stuff from child array into json array.
     * This method is needed since ajax handling methods currently need to put scriptCalls before and after child code.
     *
     * @param array $jsonResult Given json result
     * @param array $childResult Given child result
     * @return array Merged json array
     */
    protected function mergeChildResultIntoJsonResult(array $jsonResult, array $childResult)
    {
        $jsonResult['data'] = $childResult['html'];
        $jsonResult['stylesheetFiles'] = $childResult['stylesheetFiles'];
        if (!empty($childResult['inlineData'])) {
            $jsonResult['scriptCall'][] = 'inline.addToDataArray(' . json_encode($childResult['inlineData']) . ');';
        }
        if (!empty($childResult['additionalJavaScriptSubmit'])) {
            $additionalJavaScriptSubmit = implode('', $childResult['additionalJavaScriptSubmit']);
            $additionalJavaScriptSubmit = str_replace(array(CR, LF), '', $additionalJavaScriptSubmit);
            $jsonResult['scriptCall'][] = 'TBE_EDITOR.addActionChecks("submit", "' . addslashes($additionalJavaScriptSubmit) . '");';
        }
        foreach ($childResult['additionalJavaScriptPost'] as $singleAdditionalJavaScriptPost) {
            $jsonResult['scriptCall'][] = $singleAdditionalJavaScriptPost;
        }
        $jsonResult['scriptCall'][] = $childResult['extJSCODE'];
        if (!empty($childResult['requireJsModules'])) {
            foreach ($childResult['requireJsModules'] as $module) {
                $moduleName = null;
                $callback = null;
                if (is_string($module)) {
                    // if $module is a string, no callback
                    $moduleName = $module;
                    $callback = null;
                } elseif (is_array($module)) {
                    // if $module is an array, callback is possible
                    foreach ($module as $key => $value) {
                        $moduleName = $key;
                        $callback = $value;
                        break;
                    }
                }
                if ($moduleName !== null) {
                    $inlineCodeKey = $moduleName;
                    $javaScriptCode = 'require(["' . $moduleName . '"]';
                    if ($callback !== null) {
                        $inlineCodeKey .= sha1($callback);
                        $javaScriptCode .= ', ' . $callback;
                    }
                    $javaScriptCode .= ');';
                    $jsonResult['scriptCall'][] = '/*RequireJS-Module-' . $inlineCodeKey . '*/' . LF . $javaScriptCode;
                }
            }
        }
        return $jsonResult;
    }

    /**
     * Gets an array with the uids of related records out of a list of items.
     * This list could contain more information than required. This methods just
     * extracts the uids.
     *
     * @param string $itemList The list of related child records
     * @return array An array with uids
     */
    protected function getInlineRelatedRecordsUidArray($itemList)
    {
        $itemArray = GeneralUtility::trimExplode(',', $itemList, true);
        // Perform modification of the selected items array:
        foreach ($itemArray as &$value) {
            $parts = explode('|', $value, 2);
            $value = $parts[0];
        }
        unset($value);
        return $itemArray;
    }

    /**
     * Checks if a record selector may select a certain file type
     *
     * @param array $selectorConfiguration
     * @param array $fileRecord
     * @return bool
     * @todo: check this ...
     */
    protected function checkInlineFileTypeAccessForField(array $selectorConfiguration, array $fileRecord)
    {
        if (!empty($selectorConfiguration['PA']['fieldConf']['config']['appearance']['elementBrowserAllowed'])) {
            $allowedFileExtensions = GeneralUtility::trimExplode(
                ',',
                $selectorConfiguration['PA']['fieldConf']['config']['appearance']['elementBrowserAllowed'],
                true
            );
            if (!in_array(strtolower($fileRecord['extension']), $allowedFileExtensions, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return expand / collapse state array for a given table / uid combination
     *
     * @param string $table Handled table
     * @param int $uid Handled uid
     * @return array
     */
    protected function getInlineExpandCollapseStateArrayForTableUid($table, $uid)
    {
        $inlineView = $this->getInlineExpandCollapseStateArray();
        $result = array();
        if (MathUtility::canBeInterpretedAsInteger($uid)) {
            if (!empty($inlineView[$table][$uid])) {
                $result = $inlineView[$table][$uid];
            }
        }
        return $result;
    }

    /**
     * Get expand / collapse state of inline items
     *
     * @return array
     */
    protected function getInlineExpandCollapseStateArray()
    {
        $backendUser = $this->getBackendUserAuthentication();
        $inlineView = unserialize($backendUser->uc['inlineView']);
        if (!is_array($inlineView)) {
            $inlineView = array();
        }
        return $inlineView;
    }

    /**
     * Remove an element from an array.
     *
     * @param mixed $needle The element to be removed.
     * @param array $haystack The array the element should be removed from.
     * @param mixed $strict Search elements strictly.
     * @return array The array $haystack without the $needle
     */
    protected function removeFromArray($needle, $haystack, $strict = null)
    {
        $pos = array_search($needle, $haystack, $strict);
        if ($pos !== false) {
            unset($haystack[$pos]);
        }
        return $haystack;
    }

    /**
     * Generates an error message that transferred as JSON for AJAX calls
     *
     * @param string $message The error message to be shown
     * @return array The error message in a JSON array
     */
    protected function getErrorMessageForAJAX($message)
    {
        return [
            'data' => $message,
            'scriptCall' => [
                'alert("' . $message . '");'
            ],
        ];
    }

    /**
     * Get inlineFirstPid from a given objectId string
     *
     * @param string $domObjectId The id attribute of an element
     * @return int|NULL Pid or null
     */
    protected function getInlineFirstPidFromDomObjectId($domObjectId)
    {
        // Substitute FlexForm addition and make parsing a bit easier
        $domObjectId = str_replace('---', ':', $domObjectId);
        // The starting pattern of an object identifier (e.g. "data-<firstPidValue>-<anything>)
        $pattern = '/^data' . '-' . '(.+?)' . '-' . '(.+)$/';
        if (preg_match($pattern, $domObjectId, $match)) {
            return $match[1];
        }
        return null;
    }

    /**
     * @return BackendUserAuthentication
     */
    protected function getBackendUserAuthentication()
    {
        return $GLOBALS['BE_USER'];
    }
}
