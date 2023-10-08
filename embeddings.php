<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;

// Add at the beginning of the code
$start_time = microtime(true);

// ...

$OPENAI_API_KEY = "sk-...";
$inputString = "10 Best Desk For Programming";
$textContent = "<p>When it comes to finding the best desk for programming, there are a few key things to consider. First and foremost, you want a desk that is ergonomic and comfortable to use for long periods. You also want a desk with ample space for your computer, monitors, and other equipment. Some great options include standing desks, adjustable-height desks, and L-shaped desks with ample workspace.</p>";

$client = new Client(['base_uri' => 'https://api.openai.com/v1/']);

function get_embedding($text) {
    global $client, $OPENAI_API_KEY;
    $response = $client->post('embeddings', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $OPENAI_API_KEY,
        ],
        'json' => [
            'input' => $text,
            'model' => 'text-embedding-ada-002',
        ],
    ]);

    $data = json_decode($response->getBody(), true);
    return $data['data'][0]['embedding'];
}

$inputEmbedding = get_embedding($inputString);
$plainTextContent = strip_tags($textContent);

function cosine_similarity($vec1, $vec2) {
    $dotProduct = 0.0;
    for ($i = 0; $i < count($vec1); $i++) {
        $dotProduct += $vec1[$i] * $vec2[$i];
    }
    return $dotProduct;
}


$inputEmbedding2 = get_embedding($textContent);
$similarity = cosine_similarity($inputEmbedding, $inputEmbedding2);

echo $similarity;
echo "Max similarity: $maxSimilarity\n";
echo "Most similar substring: $mostSimilarSubstring\n";

$similarityThreshold = 0.8;
if ($maxSimilarity > $similarityThreshold) {
    $url = "https://example.com/10-Best-Desk-For-Programming";
    $anchorText = "<a href=\"$url\">$mostSimilarSubstring</a>";
    $result = preg_replace('/\b(?:' . preg_quote($mostSimilarSubstring, '/') . ')\b/i', $anchorText, $textContent);
    echo $result;
} else {
    echo "Similarity threshold not met. No link added.\n";
    echo $textContent;
}


// ...

// Add at the end of the code
$end_time = microtime(true);
$time_elapsed_secs = $end_time - $start_time;
echo "\nExecution time: $time_elapsed_secs seconds\n";
