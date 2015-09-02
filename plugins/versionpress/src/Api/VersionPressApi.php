<?php

namespace VersionPress\Api;

require_once ABSPATH . 'wp-admin/includes/file.php';

use Nette\Utils\Strings;
use VersionPress\Api\BundledWpApi\WP_REST_Request;
use VersionPress\Api\BundledWpApi\WP_REST_Response;
use VersionPress\Api\BundledWpApi\WP_REST_Server;
use VersionPress\ChangeInfos\ChangeInfoEnvelope;
use VersionPress\ChangeInfos\ChangeInfoMatcher;
use VersionPress\ChangeInfos\EntityChangeInfo;
use VersionPress\ChangeInfos\PluginChangeInfo;
use VersionPress\ChangeInfos\RevertChangeInfo;
use VersionPress\ChangeInfos\ThemeChangeInfo;
use VersionPress\ChangeInfos\TrackedChangeInfo;
use VersionPress\ChangeInfos\VersionPressChangeInfo;
use VersionPress\ChangeInfos\WordPressUpdateChangeInfo;
use VersionPress\Configuration\VersionPressConfig;
use VersionPress\DI\VersionPressServices;
use VersionPress\Git\Commit;
use VersionPress\Git\GitLogPaginator;
use VersionPress\Git\GitRepository;
use VersionPress\Git\Reverter;
use VersionPress\Git\RevertStatus;
use VersionPress\Initialization\VersionPressOptions;
use VersionPress\Utils\BugReporter;

class VersionPressApi {

    /**
     * Register the VersionPress related routes
     */
    public function register_routes() {
        $namespace = 'versionpress';

        register_vp_rest_route($namespace, '/commits', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'getCommits'),
            'args' => array(
                'page' => array(
                    'default' => '0'
                )
            ),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/undo', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'undoCommit'),
            'args' => array(
                'commit' => array(
                    'required' => true
                )
            ),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/rollback', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'rollbackToCommit'),
            'args' => array(
                'commit' => array(
                    'required' => true
                )
            ),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/can-revert', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'canRevert'),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/diff', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'getDiff'),
            'args' => array(
                'commit' => array(
                    'required' => true
                )
            ),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/submit-bug', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'submitBug'),
            'args' => array(
                'email' => array(
                    'required' => true
                ),
                'description' => array(
                    'required' => true
                )
            ),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/display-welcome-panel', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'displayWelcomePanel'),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/hide-welcome-panel', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'hideWelcomePanel'),
            'permission_callback' => array($this, 'checkPermissions')
        ));

        register_vp_rest_route($namespace, '/should-update', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'shouldUpdate'),
            'permission_callback' => array($this, 'checkPermissions')
        ));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|\WP_Error
     */
    public function getCommits(WP_REST_Request $request) {
        global $versionPressContainer;
        /** @var GitRepository $repository */
        $repository = $versionPressContainer->resolve(VersionPressServices::REPOSITORY);
        $gitLogPaginator = new GitLogPaginator($repository);
        $gitLogPaginator->setCommitsPerPage(25);

        $page = intval($request['page']);
        $commits = $gitLogPaginator->getPage($page);

        if (empty($commits)) {
            return new \WP_Error('notice', 'No more commits to show.', array('status' => 403));
        }

        $preActivationHash = trim(file_get_contents(VERSIONPRESS_ACTIVATION_FILE));
        if (empty($preActivationHash)) {
            $initialCommitHash = $repository->getInitialCommit()->getHash();
        } else {
            $initialCommitHash = $repository->getChildCommit($preActivationHash);
        }

        $canUndoCommit = $repository->wasCreatedAfter($commits[0]->getHash(), $initialCommitHash);
        $isFirstCommit = $page === 0;

        $result = array();
        foreach ($commits as $commit) {
            $canUndoCommit = $canUndoCommit && ($commit->getHash() !== $initialCommitHash);
            $canRollbackToThisCommit = !$isFirstCommit && ($canUndoCommit || $commit->getHash() === $initialCommitHash);
            $changeInfo = ChangeInfoMatcher::buildChangeInfo($commit->getMessage());
            $isEnabled = $canUndoCommit || $canRollbackToThisCommit || $commit->getHash() === $initialCommitHash;


            $fileChanges = $this->getFileChanges($commit);
            $changeInfoList = $changeInfo instanceof ChangeInfoEnvelope ? $changeInfo->getChangeInfoList() : array();

            $result[] = array(
                "hash" => $commit->getHash(),
                "date" => $commit->getDate()->format('c'),
                "message" => $changeInfo->getChangeDescription(),
                "canUndo" => $canUndoCommit,
                "canRollback" => $canRollbackToThisCommit,
                "isEnabled" => $isEnabled,
                "isInitial" => $commit->getHash() === $initialCommitHash,
                "changes" => array_merge($this->convertChangeInfoList($changeInfoList), $fileChanges),
            );
            $isFirstCommit = false;
        }
        return new WP_REST_Response(array(
            'pages' => $gitLogPaginator->getPrettySteps($page),
            'commits' => $result
        ));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|\WP_Error
     */
    public function undoCommit(WP_REST_Request $request) {
        return $this->revertCommit('undo', $request['commit']);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|\WP_Error
     */
    public function rollbackToCommit(WP_REST_Request $request) {
        return $this->revertCommit('rollback', $request['commit']);
    }

    /**
     * @return WP_REST_Response|\WP_Error
     */
    public function canRevert() {
        global $versionPressContainer;
        /** @var GitRepository $repository */
        $repository = $versionPressContainer->resolve(VersionPressServices::REPOSITORY);
        /** @var Reverter $reverter */
        $reverter = $versionPressContainer->resolve(VersionPressServices::REVERTER);

        return new WP_REST_Response($reverter->canRevert());
    }

    /**
     * @param string $reverterMethod
     * @param string $commit
     * @return WP_REST_Response|\WP_Error
     */
    public function revertCommit($reverterMethod, $commit) {
        global $versionPressContainer;
        /** @var GitRepository $repository */
        $repository = $versionPressContainer->resolve(VersionPressServices::REPOSITORY);
        /** @var Reverter $reverter */
        $reverter = $versionPressContainer->resolve(VersionPressServices::REVERTER);

        vp_enable_maintenance();
        $revertStatus = call_user_func(array($reverter, $reverterMethod), $commit);
        vp_disable_maintenance();

        if ($revertStatus !== RevertStatus::OK) {
            return $this->getError($revertStatus);
        }
        return new WP_REST_Response(true);
    }

    public function getDiff(WP_REST_Request $request) {
        global $versionPressContainer;
        /** @var GitRepository $repository */
        $repository = $versionPressContainer->resolve(VersionPressServices::REPOSITORY);
        $hash = $request['commit'];
        $diff = $repository->getDiff($hash);

        if (strlen($diff) > 50 * 1024) { // 50 kB is maximum size for diff (see WP-49)
            return new \WP_Error(
                'error',
                'The diff is too large to show here. Please use some git client. Thank you.',
                array('status' => 403));
        }

        return new WP_REST_Response(array('diff' => $diff));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|\WP_Error
     */
    public function submitBug(WP_REST_Request $request) {
        $email = $request['email'];
        $description = $request['description'];

        $bugReporter = new BugReporter('http://versionpress.net/report-problem');
        $reportedSuccessfully = $bugReporter->reportBug($email, $description);

        if ($reportedSuccessfully) {
            return new WP_REST_Response(true);
        } else {
            return new \WP_Error(
                'error',
                'There was a problem with sending bug report. Please try it again. Thank you.',
                array('status' => 403)
            );
        }
    }

    /**
     * @return WP_REST_Response
     */
    public function displayWelcomePanel() {
        $showWelcomePanel = get_user_meta(get_current_user_id(), VersionPressOptions::USER_META_SHOW_WELCOME_PANEL, true);
        return new WP_REST_Response($showWelcomePanel === "");
    }

    /**
     * @return WP_REST_Response
     */
    public function hideWelcomePanel() {
        update_user_meta(get_current_user_id(), VersionPressOptions::USER_META_SHOW_WELCOME_PANEL, "0");
        return new WP_REST_Response(null, 204);
    }

    public function shouldUpdate(WP_REST_Request $request) {
        global $versionPressContainer;
        /** @var GitRepository $repository */
        $repository = $versionPressContainer->resolve(VersionPressServices::REPOSITORY);

        $latestCommit = $request['latestCommit'];

        return new WP_REST_Response($repository->wasCreatedAfter("HEAD", $latestCommit));
    }

    /**
     * @param string $status
     * @return \WP_Error
     */
    public function getError($status) {
        $errors = array(
            RevertStatus::MERGE_CONFLICT => array(
                'class' => 'error',
                'message' => 'Error: Overwritten changes can not be reverted.',
                'status' => 403
            ),
            RevertStatus::NOTHING_TO_COMMIT => array(
                'class' => 'updated',
                'message' => 'There was nothing to commit. Current state is the same as the one you want rollback to.',
                'status' => 200
            ),
            RevertStatus::VIOLATED_REFERENTIAL_INTEGRITY => array(
                'class' => 'error',
                'message' => 'Error: Objects with missing references cannot be restored. For example we cannot restore comment where the related post was deleted.',
                'status' => 403
            ),
        );

        $error = $errors[$status];
        return new \WP_Error(
            $error['class'],
            $error['message'],
            array('status' => $error['status'])
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return \WP_Error|bool
     */
    public function checkPermissions(WP_REST_Request $request) {
        global $versionPressContainer;
        /** @var VersionPressConfig $vpConfig */
        $vpConfig = $versionPressContainer->resolve(VersionPressServices::VP_CONFIGURATION);

        return !$vpConfig->mergedConfig['requireApiAuth'] || current_user_can('manage_options');
    }

    private function convertChangeInfoList($getChangeInfoList) {
        return array_map(array($this, 'convertChangeInfo'), $getChangeInfoList);
    }

    private function convertChangeInfo($changeInfo) {
        $change = array();

        if ($changeInfo instanceof TrackedChangeInfo) {
            $change['type'] = $changeInfo->getEntityName();
            $change['action'] = $changeInfo->getAction();
            $change['tags'] = $changeInfo->getCustomTags();
        }

        if ($changeInfo instanceof EntityChangeInfo) {
            $change['name'] = $changeInfo->getEntityId();
        }

        if ($changeInfo instanceof PluginChangeInfo) {
            $pluginTags = $changeInfo->getCustomTags();
            $pluginName = $pluginTags[PluginChangeInfo::PLUGIN_NAME_TAG];
            $change['name'] = $pluginName;
        }

        if ($changeInfo instanceof ThemeChangeInfo) {
            $themeTags = $changeInfo->getCustomTags();
            $themeName = $themeTags[ThemeChangeInfo::THEME_NAME_TAG];
            $change['name'] = $themeName;
        }

        if ($changeInfo instanceof WordPressUpdateChangeInfo) {
            $change['name'] = $changeInfo->getNewVersion();
        }

        return $change;
    }

    /**
     * @param Commit $commit
     * @return array
     */
    private function getFileChanges(Commit $commit) {
        $changedFiles = $commit->getChangedFiles();

        $changedFiles = array_filter($changedFiles, function ($changedFile) {
            $path = str_replace('\\', '/', ABSPATH . $changedFile['path']);
            $vpdbPath = str_replace('\\', '/', VERSIONPRESS_MIRRORING_DIR);

            return !Strings::startsWith($path, $vpdbPath);
        });

        $fileChanges = array_map(function ($changedFile) {
            $status = $changedFile['status'];
            $filename = $changedFile['path'];

            return array(
                'type' => 'file',
                'action' => $status === 'A' ? 'add' : ($status === 'M' ? 'modify' : 'delete'),
                'name' => $filename,
            );
        }, $changedFiles);

        return $fileChanges;
    }
}