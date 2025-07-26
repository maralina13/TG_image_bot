<?php

require 'vendor/autoload.php';
require 'config.php';
require 'ImageProcessor.php';

use Telegram\Bot\Api;
use Telegram\Bot\HttpClients\GuzzleHttpClient;
use GuzzleHttp\Client;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!is_dir('storage')) {
    mkdir('storage');
}

file_put_contents("storage/log.txt", date('Y-m-d H:i:s') . " | raw=" . file_get_contents("php://input") . PHP_EOL, FILE_APPEND);

$config = require 'config.php';

$guzzleClient = new Client(['verify' => false]);
$httpClient = new GuzzleHttpClient($guzzleClient);
$telegram = new Api($config['bot_token'], false, $httpClient);

$update = json_decode(file_get_contents("php://input"), true);

if (!isset($update['message']) && !isset($update['callback_query'])) {
    exit;
}

function buildMenu(array $buttons) {
    return json_encode(['inline_keyboard' => $buttons]);
}

if (isset($update['message'])) {
    $message = $update['message'];

    if (!isset($message['chat']['id'])) {
        exit;
    }

    $chatId = $message['chat']['id'];

    if (isset($message['text'])) {
        $text = $message['text'];

        if ($text === '/start') {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "Привет! Пришли мне изображение, и я его обработаю 🖼️"
            ]);
        } else {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "Ты написал: $text"
            ]);
        }
    } elseif (isset($message['photo']) || (isset($message['document']) && isset($message['document']['mime_type']) && ImageProcessor::isImageFile($message['document']['mime_type']))) {
        $fileId = isset($message['photo']) ? end($message['photo'])['file_id'] : $message['document']['file_id'];

        $file = $telegram->getFile(['file_id' => $fileId]);
        $filePath = $file->file_path;
        $fileUrl = "https://api.telegram.org/file/bot{$config['bot_token']}/{$filePath}";

        $localPath = 'storage/original_' . time() . '.' . pathinfo($filePath, PATHINFO_EXTENSION);
        file_put_contents($localPath, file_get_contents($fileUrl));

        $buttons = [
            [['text' => '📐 Кадрировать', 'callback_data' => 'resize']],
            [['text' => '⚫ Преобразовать в ч/б', 'callback_data' => 'bw']],
            [['text' => '💾 Сменить формат', 'callback_data' => 'convert']],
            [['text' => '📤 Отправить новое изображение', 'callback_data' => 'new_upload']]
        ];

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Выберите действие:',
            'reply_markup' => buildMenu($buttons)
        ]);

        file_put_contents("storage/last_image_$chatId.txt", $localPath);
        file_put_contents("storage/current_step_$chatId.txt", 'main_menu');
    }
}

if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chatId = $callback['message']['chat']['id'];
    $data = $callback['data'];

    $currentStep = file_get_contents("storage/current_step_$chatId.txt") ?? '';
    $allowedSteps = [
        'main_menu' => ['resize', 'bw', 'convert', 'new_upload'],
        'resize' => ['resize_square', 'resize_portrait', 'resize_landscape', 'back_main'],
        'convert' => ['convert_png', 'convert_jpg', 'convert_tiff', 'back_main'],
        'bw' => ['back_main'],
        'result_shown' => ['back_main']
    ];

    $valid = false;
    foreach ($allowedSteps[$currentStep] ?? [] as $allowed) {
        if ($data === $allowed) {
            $valid = true;
            break;
        }
    }
    if (!$valid) {
        $telegram->answerCallbackQuery(['callback_query_id' => $callback['id'], 'text' => 'Эта кнопка уже неактивна', 'show_alert' => false]);
        exit;
    }

    $imagePath = file_get_contents("storage/last_image_$chatId.txt");

    switch ($data) {
        case 'resize':
            file_put_contents("storage/current_step_$chatId.txt", 'resize');
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Выберите размер:',
                'reply_markup' => buildMenu([
                    [['text' => 'Квадрат', 'callback_data' => 'resize_square']],
                    [['text' => 'Портрет', 'callback_data' => 'resize_portrait']],
                    [['text' => 'Альбом', 'callback_data' => 'resize_landscape']],
                    [['text' => '🔙 Назад', 'callback_data' => 'back_main']]
                ])
            ]);
            break;

        case 'bw':
            file_put_contents("storage/current_step_$chatId.txt", 'bw');
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '⏳ Преобразование в ч/б...'
            ]);
            $newPath = ImageProcessor::convertToGrayscale($imagePath);
            file_put_contents("storage/current_step_$chatId.txt", 'result_shown');
            $telegram->sendPhoto([
                'chat_id' => $chatId,
                'photo' => fopen($newPath, 'r'),
                'reply_markup' => buildMenu([
                    [['text' => '🔙 Назад', 'callback_data' => 'back_main']]
                ])
            ]);
            break;

        case 'convert':
            file_put_contents("storage/current_step_$chatId.txt", 'convert');
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Выберите формат:',
                'reply_markup' => buildMenu([
                    [['text' => 'PNG', 'callback_data' => 'convert_png']],
                    [['text' => 'JPG', 'callback_data' => 'convert_jpg']],
                    [['text' => 'TIFF', 'callback_data' => 'convert_tiff']],
                    [['text' => '🔙 Назад', 'callback_data' => 'back_main']]
                ])
            ]);
            break;

        case 'resize_square':
        case 'resize_portrait':
        case 'resize_landscape':
            $size = explode('_', $data)[1];
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '⏳ Кадрирование изображения...'
            ]);
            $newPath = ImageProcessor::resizeToStandard($imagePath, $size);
            file_put_contents("storage/current_step_$chatId.txt", 'result_shown');
            $telegram->sendPhoto([
                'chat_id' => $chatId,
                'photo' => fopen($newPath, 'r'),
                'reply_markup' => buildMenu([
                    [['text' => '🔙 Назад', 'callback_data' => 'back_main']]
                ])
            ]);
            break;

        case 'convert_png':
        case 'convert_jpg':
        case 'convert_tiff':
            $format = explode('_', $data)[1];
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "⏳ Преобразование в формат $format..."
            ]);
            $newPath = ImageProcessor::convertFormat($imagePath, $format);
            file_put_contents("storage/current_step_$chatId.txt", 'result_shown');
            $telegram->sendDocument([
                'chat_id' => $chatId,
                'document' => fopen($newPath, 'r'),
                'reply_markup' => buildMenu([
                    [['text' => '🔙 Назад', 'callback_data' => 'back_main']]
                ])
            ]);
            break;

        case 'back_main':
            file_put_contents("storage/current_step_$chatId.txt", 'main_menu');
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Выберите действие:',
                'reply_markup' => buildMenu([
                    [['text' => '📐 Кадрировать', 'callback_data' => 'resize']],
                    [['text' => '⚫ Преобразовать в ч/б', 'callback_data' => 'bw']],
                    [['text' => '💾 Сменить формат', 'callback_data' => 'convert']],
                    [['text' => '📤 Отправить новое изображение', 'callback_data' => 'new_upload']]
                ])
            ]);
            break;

        case 'new_upload':
            file_put_contents("storage/current_step_$chatId.txt", 'main_menu');
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пришлите новое изображение, чтобы продолжить.'
            ]);
            break;
    }

    $telegram->answerCallbackQuery(['callback_query_id' => $callback['id']]);
}
