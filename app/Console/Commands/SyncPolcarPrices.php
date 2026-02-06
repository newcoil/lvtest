<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Webklex\IMAP\Facades\Client;
use Maatwebsite\Excel\Facades\Excel;


class SyncPolcarPrices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prices:sync-polcar';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'IMAP-прайсинг + обновление наличия/цен + отчёт';

    /**
     * индикатор процесса обновления данных.
     * если 0 данные еще не обновлялись
     * если 1 старая таблица очищена и данные обновляются
     * @var int
     */
    protected $isUpdated = 0;
    
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Подключаемся к IMAP серверу");       

        //соединяемся с IMAP сервером через Laravel-IMAP Client        
        
        $client = Client::account('default');

        $client->connect();

        $folder = $client->getFolderByName(env('IMAP_FOLDER'));    

        //проверяем существует ли нужная папка (INBOX) на сервере
        
        if (!$folder) {
            $this->info("Папка " . env('IMAP_FOLDER') . " в почте не найдена");
            $this->info("Проверьте настройки IMAP_FOLDER в .env");
            return;
        }

        $this->info("Проверка почты");

        //Получаем непрочитанную почту из INBOХ c даты последнего обновления цен
        //IMAP Client НЕ ПОЗВОЛЯЕТ desc сортировку, поэтому новые письма следует читать с конца
        //параметр since устанавливается на дату последнего обновления, полученного из processed_mail_items
        //дата может не соответствовать часовым поясам разных серверов, но это не критично (+3)

        $messages = $folder->query()->unseen()->since($this->lastRecord()[2])->get();

        //проверяем есть ли непрочитанные сообщения
        
        $mcount = count($messages);

        if ($mcount < 1) {
            $this->info("Новых писем нет");
            return;
        }

        //обратный перебор начиная с новых // IMAP Client НЕ ПОЗВОЛЯЕТ desc сортировку для foreach

        for ($i = $mcount - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            $attachment = $message->getAttachments()->count();

            //находим письмо с прикрепленным файлом\файлами

            if ($attachment > 0) {

                //получаем IMAP ID для уникализации имени файла, сохраняемого на сервере
                //!!!! IMAP ID уникален только в пределах своей папки! (INBOX);

                $muid = $message->getUid();

                //проверка по processed_mail_items

                if ($muid == $this->lastRecord()[0]) {
                    //данное письмо самое новое и УЖЕ обработано
                    $this->info("Новых писем нет"); 
                    return;
                }

                //перебор вложений в письме, так как их может быть несколько

                foreach ($message->getAttachments() as $attachment) {

                    //ищем MIME тип файла эксела во вложении

                    if ( str_starts_with($attachment->getContentType(), 'application/vnd.openxmlformats') && str_contains($attachment->name, '.xls') ) {

                        $fileName = $muid . "_" . $attachment->name;

                        $fullFileName = storage_path('app\prices\contractor_11') . '\\' . $fileName;

                        //проверка файла на сервере 
                        //если processed_mail_items повреждена
                        //если сохранены файлы excel с некорректными данными

                        if (file_exists($fullFileName)) {
                            $this->info("файл не содержит актуальных данных");
                            return;
                        }

                        //сохраняем файл
                        $attachment->save(storage_path('app\prices\contractor_11'), $fileName);
                        $this->info("Файл $fileName сохранен");

                        //запись processed_mail_items

                        $mailItem = [
                                'name' => $fileName,
                                'uid' => $muid,
                                'created_at' => now(),
                                'updated_at' => now(),
                        ];   
                        
                        DB::table('processed_mail_items')->insert($mailItem);

                        //читаем excel в массив

                        $data = Excel::toArray([], $fullFileName)[0]; 

                        //общее количество строк без заголовка  

                        $rows = count($data) - 1;

                        //проверяем файл по содержимому - наличие строк и названия первых двух заголовков столбцов

                        if ( $rows > 1 && $data[0][0] == 'PIN' && $data[0][1] == 'GTIN') {

                            //создаем заголовок для cvs отчета 

                            $csvData = "PIN,Цена,Количество\n";
                            
                            //инициализируем счетчик найденых актуальных строк

                            $iii = 0;

                            //производим операции с каждой строкой

                            for ($ii = 1; $ii <= $rows; $ii++) {    
                                                    
                                //проверка соответствия PIN-oem, если да - обновляем данные в базе, добавляем строку в CVS

                                if (in_array($data[$ii][0], $this->actualPins())) {

                                    //создаем строку для contractor_prices                                        

                                    $price = [
                                        'article_id' => $data[$ii][0],
                                        'price' => (float)strtr($data[$ii][3], ',', '.'), //конвертируем эксел формат для sql decimal, запятые в точки
                                        'amount' => intval($data[$ii][6]),                   
                                        'contractor_id' => 11,
                                        'delivery_date' => null, 
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ];

                                    //добавляем строку в CVS                                        

                                    $csvData = $csvData . $data[$ii][0] . "," .(float)strtr($data[$ii][3], ',', '.') . "," . intval($data[$ii][6])."\n";

                                    //если записи еще не добавлялись и старые данные не удалены

                                    if ($this->isUpdated == 0) {

                                        //удаляем старые данные из таблицы
                                        DB::table('contractor_prices')->where('contractor_id', 11)->delete();
                                        
                                        //указываем, что старые данные уже удалены и обновление в процессе
                                        $this->isUpdated = 1;
                                    }

                                    //добавляем строку в contractor_prices

                                    DB::table('contractor_prices')->insert($price);              

                                    //+1 в счетчик новых строк: строка найдена и добавлена

                                    $iii++;

                                } //действия над строкой завершены                                 

                            } //все строки проверены

                            //если актуальные строки были найдены и добавлены

                            if ($iii > 0) {
                                $this->info("Отправляем отчет CSV");
                                $this->report($iii, $rows, $csvData);
                                $this->info("Отчет CSV отправлен");
                                $this->info("Обновлено " . $iii . " строк из прайса ( всего " . $rows . " строк )");
                                return;
                            } else {
                                $this->info("Данные для обновления отсутствуют в файле");
                                $this->info("Обновлено 0 строк из прайса ( всего " . $rows . " строк )");    
                            }

                        } // else содержимое файла не соответствует = не прайс
                       
                    } // else MIME тип файла не эксель

                } // end foreach перебора вложений в письме

            } // else в письме нет прикрепленных файлов

        } //endfor обратного перебора писем 

    }




    /**
     * актуальный список ПИН/oem на момент обновления
     * return @array
     */    
    protected function actualPins()
    {
        $pins = DB::table('lara_polcar_items')->select('oem')->get();
        $apins = array();
        foreach ($pins as $pin) {
            $apins[] = $pin->oem;
        }
        return($apins);
    }

    /**
     * идентификация последнего обработанного файла из таблицы processed_mail_items
     * return @array
     */
    protected function lastRecord() 
    {
        $nameUid = [0, 'not_exist.xlsx', '2026-01-05 13:06:36'];  
        $record = DB::table('processed_mail_items')->orderBy('created_at', 'desc')->first();
        if($record){ //запись существует
            $nameUid = [$record->uid, $record->name, $record->created_at];  
        }
        return($nameUid);
    }
   
    /**
     * отправка отчета c csv
     */    
    protected function report($iii, $rows, $csvData) 
    {
        Mail::raw("Обновлено " . $iii . " строк из прайса ( всего " . $rows . " строк )", function ($message) use ($csvData) {
        $message->to(env("REPORT_EMAIL_TO"))
                                        ->subject('ОТЧЕТ CSV')
                                        ->attachData($csvData, 'report.csv', [
                                            'mime' => 'text/csv',
                                        ]);
        });
        return true;
    }



}
