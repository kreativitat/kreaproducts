<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

require_once __DIR__.'/../class/KreaProductsLlmProductDataService.class.php';

function assertLlmSame($expected, $actual, $message)
{
	if ($expected !== $actual) {
		fwrite(STDERR, "Assertion failed: ".$message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
		exit(1);
	}
}

function expectInvalidSuggestion($suggestion, $message)
{
	try {
		KreaProductsLlmProductDataService::normalizeSuggestion($suggestion);
	} catch (InvalidArgumentException $exception) {
		return;
	}
	fwrite(STDERR, "Assertion failed: ".$message."\n");
	exit(1);
}

$nutrition = array(
	'energy_kcal' => 100,
	'energy_kj' => 418.4,
	'fat' => 4,
	'saturates' => 1,
	'carbohydrates' => 12,
	'sugars' => 3,
	'protein' => 5,
	'salt' => 0.5,
	'fiber' => 2,
);
$validSuggestion = array(
	'usable' => true,
	'nutrition_per_100g' => $nutrition,
	'allergens' => array(
		array('code' => 'LEIT', 'presence' => 'traces'),
		array('code' => 'LEIT', 'presence' => 'contains'),
		array('code' => 'GLUT', 'presence' => 'contains'),
	),
	'confidence' => 'medium',
	'notes' => 'Check the package label.',
);

$normalized = KreaProductsLlmProductDataService::normalizeSuggestion($validSuggestion);
assertLlmSame(2, count($normalized['allergens']), 'Duplicate allergen codes must be collapsed.');
assertLlmSame('contains', $normalized['allergens'][0]['presence'], 'Contains must override traces for the same allergen.');
assertLlmSame(418.4, $normalized['nutrition_per_100g']['energy_kj'], 'Valid energy values must be preserved.');

$schema = array('type' => 'object');
$openAi = KreaProductsLlmProductDataService::buildProviderRequest('openai', 'test-model', 'prompt', $schema);
assertLlmSame('json_schema', $openAi['response_format']['type'], 'OpenAI must use JSON Schema output.');
assertLlmSame(true, $openAi['response_format']['json_schema']['strict'], 'OpenAI schema mode must be strict.');

$anthropic = KreaProductsLlmProductDataService::buildProviderRequest('anthropic', 'test-model', 'prompt', $schema);
assertLlmSame('json_schema', $anthropic['output_config']['format']['type'], 'Claude must use JSON outputs.');
assertLlmSame(1600, $anthropic['max_tokens'], 'Claude requests must set a bounded output size.');

$openRouter = KreaProductsLlmProductDataService::buildProviderRequest('openrouter', 'test-model', 'prompt', $schema);
assertLlmSame(true, $openRouter['provider']['require_parameters'], 'OpenRouter must route only to models that support structured outputs.');

$ollama = KreaProductsLlmProductDataService::buildProviderRequest('ollama', 'test-model', 'prompt', $schema);
assertLlmSame(false, $ollama['stream'], 'Ollama structured output must disable streaming.');
assertLlmSame($schema, $ollama['format'], 'Ollama must receive the JSON Schema in its format field.');

$service = new KreaProductsLlmProductDataService(null);
$privateHostMethod = new ReflectionMethod($service, 'isAllowedPrivateOllamaHost');
$privateHostMethod->setAccessible(true);
assertLlmSame(true, $privateHostMethod->invoke($service, '127.0.0.1'), 'Ollama must allow loopback hosts.');
assertLlmSame(true, $privateHostMethod->invoke($service, '192.168.1.20'), 'Ollama must allow RFC1918 hosts.');
assertLlmSame(false, $privateHostMethod->invoke($service, '169.254.169.254'), 'Ollama must reject link-local metadata addresses.');
assertLlmSame(false, $privateHostMethod->invoke($service, '8.8.8.8'), 'Ollama must reject public addresses.');

$promptMethod = new ReflectionMethod($service, 'buildPrompt');
$promptMethod->setAccessible(true);
$testProduct = (object) array('ref' => 'TEST', 'label' => 'Beef', 'barcode' => '');
$estimationPrompt = $promptMethod->invoke($service, $testProduct, "Ingredients:\nbeef", $schema);
assertLlmSame(true, strpos($estimationPrompt, 'use general food-composition knowledge to estimate typical values') !== false, 'The prompt must allow nutrition estimates when label values are absent.');
assertLlmSame(true, strpos($estimationPrompt, 'For allergens, use only explicit product evidence') !== false, 'Allergen suggestions must remain evidence-based.');

$submitted = KreaProductsLlmProductDataService::buildSubmittedSuggestion($nutrition, array('LEIT'), array('LEIT', 'GLUT'), 'high', 'Reviewed');
assertLlmSame(2, count($submitted['allergens']), 'Reviewed allergen lists must not duplicate contains as traces.');
assertLlmSame('contains', $submitted['allergens'][0]['presence'], 'Reviewed contains selection must win over traces.');

$invalid = $validSuggestion;
$invalid['allergens'][0]['code'] = 'UNKNOWN';
expectInvalidSuggestion($invalid, 'Unknown allergen codes must fail closed.');

$invalid = $validSuggestion;
$invalid['nutrition_per_100g']['sugars'] = 20;
expectInvalidSuggestion($invalid, 'Sugars above carbohydrates must fail closed.');

$invalid = $validSuggestion;
$invalid['nutrition_per_100g']['energy_kj'] = 1000;
expectInvalidSuggestion($invalid, 'Inconsistent energy units must fail closed.');

$invalid = $validSuggestion;
foreach (KreaProductsLlmProductDataService::getNutritionFields() as $field) {
	$invalid['nutrition_per_100g'][$field] = null;
}
$invalid['allergens'] = array();
$normalizedEmpty = KreaProductsLlmProductDataService::normalizeSuggestion($invalid);
assertLlmSame(false, $normalizedEmpty['usable'], 'A provider must not be allowed to mark an empty suggestion as usable.');

print "LLM product data tests passed.\n";
