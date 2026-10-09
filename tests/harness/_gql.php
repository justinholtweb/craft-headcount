<?php
/**
 * Runs GraphQL queries against one in-memory schema and prints the results as JSON.
 *
 *     php tests/harness/_gql.php <base64 JSON job>
 *
 * The job is `{"scope": [...], "runs": [{"userId": int|null, "query": string}, ...], "caching": bool}`.
 * graphql.php runs one schema per process: Craft's GraphQL type registry can't be rebuilt
 * for a second schema in the same process (flushCaches() leaves duplicate `QueryArgument`
 * types behind). Several runs share the schema, which is how the cache check asks the same
 * query as two different members.
 */

require __DIR__ . '/_boot.php';

$job = json_decode(base64_decode($argv[1] ?? ''), true) ?: [];
$general = Craft::$app->getConfig()->getGeneral();
$general->enableGraphqlCaching = (bool)($job['caching'] ?? false);

$schema = new craft\models\GqlSchema([
    'name' => 'Headcount check',
    'uid' => 'headcount-check-' . md5(json_encode($job['scope'] ?? [])),
    'scope' => $job['scope'] ?? [],
]);

$out = [];
foreach ($job['runs'] ?? [] as $run) {
    $user = isset($run['userId']) ? Craft::$app->getUsers()->getUserById((int)$run['userId']) : null;
    Craft::$app->getUser()->setIdentity($user);

    $out[] = [
        'result' => Craft::$app->getGql()->executeQuery($schema, $run['query'], $run['variables'] ?? null),
        'cachingAfter' => $general->enableGraphqlCaching,
    ];
}

echo json_encode($out);
