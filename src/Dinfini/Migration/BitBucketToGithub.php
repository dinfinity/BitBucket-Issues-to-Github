<?php

namespace Dinfini\Migration;

use Github\HttpClient\Message\ResponseMediator;
use Guzzle\Common\Event;

class BitBucketToGithub {

    protected static $bbUser;

    protected static $bbPass;

    protected static $ghUser;

    protected static $ghPass;

    protected static $githubClient;

    /**
     *
     * @param unknown $bbUser
     * @param unknown $bbPass
     */
    public static function setBitBucketCredentials($bbUser, $bbPass) {
        self::$bbUser = $bbUser;
        self::$bbPass = $bbPass;
    }

    /**
     *
     * @param unknown $ghUser
     * @param unknown $ghPass
     */
    public static function setGithubCredentials($ghUser, $ghPass) {
        self::$ghUser = $ghUser;
        self::$ghPass = $ghPass;
    }

    /**
     */
    protected static function initGithubClient() {
        // Or select directly which cache you want to use
        $client = new \Github\HttpClient\CachedHttpClient();
        $client->setCache(
                // Built in one, or any cache implementing this interface:
                // Github\HttpClient\Cache\CacheInterface
                new \Github\HttpClient\Cache\FilesystemCache('tmp/github-api-cache'));

        self::$githubClient = new \Github\Client($client);
        self::$githubClient->authenticate(self::$ghUser, self::$ghPass, \Github\Client::AUTH_HTTP_PASSWORD);

        self::$githubClient->getHttpClient()->addListener('request.success',
                function (Event $event) {
                    $remaining = ResponseMediator::getApiLimit($event['response']);

                    self::log('Remaining calls: ' . $remaining);
                });
    }

    /**
     */
    public static function doMigrateIssues($bbAccountName, $bbRepoSlug, $ghRepo) {
        // =-- Init github connector
        /* @var $githubIssueClient \Github\Api\Issue */
        self::initGithubClient();
        $githubIssueClient = self::$githubClient->api('issue');

        // =-- Init retriever
        $issueRetriever = new \Bitbucket\API\Repositories\Issues();
        $issueRetriever->setCredentials(new \Bitbucket\API\Authentication\Basic(self::$bbUser, self::$bbPass));

        // =-- Retrieve and decode all Bitbucket issues
        $startIssue = 0;
        $limitIssues = 50;
        $retrievedIssues = -1;

        while ($retrievedIssues != 0) {
            $rawIssues = $issueRetriever->all($bbAccountName, $bbRepoSlug,
                    array ('sort' => 'local_id', 'limit' => $limitIssues, 'start' => $startIssue));
            $decodedIssues = json_decode($rawIssues->getContent());
            if (isset($decodedIssues->issues)) {
                $decodedIssues = $decodedIssues->issues;
                $retrievedIssues = sizeof($decodedIssues);
            } else {
                $decodedIssues = array ();
                $retrievedIssues = 0;
            }
            self::log("Found {$retrievedIssues} issues.");

            foreach ($decodedIssues as $decodedIssue) {

                // =-- Retrieve data from bitbucket issue
                $createdOn = $decodedIssue->utc_created_on;
                $localId = $decodedIssue->local_id;
                $type = $decodedIssue->metadata->kind;
                $milestone = $decodedIssue->metadata->milestone;
                $priority = $decodedIssue->priority;
                $creator = $decodedIssue->reported_by->username;
                $assignee = $decodedIssue->responsible->username;
                $status = $decodedIssue->status;
                $title = $decodedIssue->title;
                $content = $decodedIssue->content;
                $commentCount = $decodedIssue->comment_count;

                $creator = self::replaceCreatorWithGithubUser($creator);

                $body = "Created by {$creator} on {$createdOn}\n" . "\n\n" . $content;

                $newLabels = array (
                        'status-' . $status,
                        'milestone-' . $milestone,
                        'assignee-' . $assignee,
                        'type-' . $type,
                        'priority-' . $priority);

                // =-- Get or create issue
                try {
                    $githubIssue = $githubIssueClient->show(self::$ghUser, $ghRepo, $localId);
                    $issueId = $githubIssue['number'];
                    self::log('Found issue with number: ' . $issueId);
                } catch (\Exception $e) {
                    $githubIssue = null;
                }
                if (!$githubIssue) {
                    $githubIssue = $githubIssueClient->create(self::$ghUser, $ghRepo,
                            array ('title' => $title, 'body' => $body));
                    $issueId = $githubIssue['number'];
                    try {
                        $githubIssueClient->labels()->add(self::$ghUser, $ghRepo, $issueId, $newLabels);
                    } catch (\Exception $e) {
                        try {
                            $githubIssueClient->labels()->add(self::$ghUser, $ghRepo, $issueId, $newLabels);
                        } catch (\Exception $e) {
                            // Give up
                        }
                    }
                    self::log('Created issue with number: ' . $issueId);
                }

                // =-- Retrieve comments
                self::log("Processing [{$commentCount}] comments for issue with title:" . $githubIssue['title']);
                $githubCommentCount = $githubIssue['comments'];
                if ($commentCount > 0 && $commentCount > $githubCommentCount) {
                    $rawComments = $issueRetriever->comments()->all($bbAccountName, $bbRepoSlug, $localId);
                    $decodedComments = json_decode($rawComments->getContent());
                    usort($decodedComments,
                            function ($commentA, $commentB) {
                                $a = $commentA->utc_created_on;
                                $b = $commentB->utc_created_on;
                                if ($a == $b) {
                                    return 0;
                                } else if ($a > $b) {
                                    return 1;
                                } else if ($a < $b) {
                                    return -1;
                                }
                            });

                    foreach ($decodedComments as $comment) {
                        $commentAuthor = $comment->author_info->username;
                        $commentId = $comment->comment_id;
                        $commentContent = $comment->content;
                        $commentCreatedOn = $comment->utc_created_on;

                        $commentAuthor = self::replaceCreatorWithGithubUser($commentAuthor);

                        $commentContent = str_replace(array ('<<cset ', '>>'), array ('', ''), $commentContent);
                        $commentBody = "Written by {$commentAuthor} on {$commentCreatedOn}\n" . "\n\n" . $commentContent;

                        $githubIssueClient->comments()->create(self::$ghUser, $ghRepo, $issueId,
                                array ('body' => $commentBody));
                    }
                }
                self::log('Done processing issue with number: ' . $issueId);
            }

            $startIssue += $limitIssues;
        }
    }

    /**
     *
     * @param string $bbCreator
     */
    protected static function replaceCreatorWithGithubUser($bbCreator) {
        $transTable = array ('bitbucketCreator' => 'githubUser');
        return '@' . $transTable[$bbCreator];
    }

    protected static function log($message) {
        echo "$message\n";
    }
}