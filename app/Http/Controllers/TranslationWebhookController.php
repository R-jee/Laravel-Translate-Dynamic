<?php

namespace App\Http\Controllers;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Stichoza\GoogleTranslate\GoogleTranslate;

class TranslationWebhookController extends Controller
{
    //
    public function handle(Request $request)
    {
        Log::info("Piggy 2222");
        $translateable = $request['translate_it']  ?? "piggy222";

        $translateableString = trim($translateable);
        $translatedString = $translateable;

        $base = config('auto-translate.base_locale');
        $locales = [];
        foreach (config('auto-translate.locales') as $locale) {
            if ($locale != $base) {
                $locales[] = $locale;
            }
        }
        $basefilePath = lang_path($base . '.json');
        if (File::exists($basefilePath)) {
            $baseTranslations = json_decode(File::get(lang_path($base . '.json')), true);

            foreach ($baseTranslations as $kbt => $baseTranslation) {
                Log::info($baseTranslation);
                try {
                    $newBaseTranslations = [];
                    if (!array_key_exists($translatedString, $baseTranslations)) {

                        $translator = new GoogleTranslate();
                        $translator->setSource($base);
                        $baseTranslations[$translatedString] = $translator->translate($translatedString);
                    }else{

                    }
                } catch (\Exception $e) {
                    $this->error('Error: ' . $e->getMessage());
                    return $translatedString;
                }
            }
            File::put($basefilePath, json_encode($baseTranslations, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            //            $baseTranslations = json_decode(File::get(lang_path($base . '.json')), true);
            //
            //            foreach ($locales as $locale) {
            //                Log::info("PiggyFile");
            //                $filePath = lang_path($locale . '.json');
            //                if (File::exists($filePath)) {
            //                    $localeTranslations = json_decode(File::get(lang_path($locale . '.json')), true);
            //                    $translator = new GoogleTranslate($locale);
            //                    $translator->setSource(config('app.fallback_locale'));
            //                    $newLocaleTranslations = [];
            //                    foreach ($baseTranslations as $kbt => $baseTranslation) {
            //                        try {
            //                            if (!array_key_exists($kbt, $localeTranslations)) {
            //                                $newLocaleTranslations[$kbt] = $translator->translate($kbt);
            //                            } else {
            //                                $newLocaleTranslations[$kbt] = $localeTranslations[$kbt];
            //                            }
            //                        } catch (\Exception $e) {
            //                            $this->error('Error: ' . $e->getMessage());
            //                            $newLocaleTranslations[$kbt] = $kbt;
            //                        }
            //                    }
            //                    File::put($filePath, json_encode($newLocaleTranslations, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            //                }
            //            }

        }// end if basefilepath

        return $translateable;

    }
}
