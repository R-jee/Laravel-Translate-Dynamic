<?php
Route::get('/webhook/translations/{translate_it}', [TranslationWebhookController::class, 'handle']);

?>
