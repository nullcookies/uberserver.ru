<?php

namespace App\Http\Controllers;

use App\Alice;
use App\AliceSecure;
use App\Helpers\MqttHelper;
use App\Helpers\TelegramHelper;
use App\Weather;
use Illuminate\Http\Request;

class AliceController extends Controller
{
    private $validUser;
    private $isAdmin;
    private $user_id;
    private $end_session;

    public function __construct()
    {
        $this->validUser  = false;
        $this->isAdmin = false;
        $this->user_id = false;
        $this->end_session = false;
    }

    public function actionIndex()
    {
        $apiRequestArray = json_decode(trim(file_get_contents('php://input')), true);
        $text = $this->process($apiRequestArray);
        $arrayToEncode = [
            'response' => [
                'text' => htmlspecialchars_decode($text),
                'tts'  => htmlspecialchars_decode($text),
                'buttons' => [],
                //'end_session' => $this->end_session,
                'end_session' => $this->end_session,
            ],
            'session' => [
                'session_id' => $apiRequestArray['session']['session_id'],
                'message_id' => $apiRequestArray['session']['message_id'],
                'user_id' => $apiRequestArray['session']['user_id'],
            ],
            'version' => $apiRequestArray['version']
        ];

        return response()->json($arrayToEncode);

    }

    public function actionSmartHome()
    {
        $apiRequestArray = json_decode(trim(file_get_contents('php://input')), true);
        $text = $this->process($apiRequestArray);
        $arrayToEncode = [
            'response' => [
                'text' => htmlspecialchars_decode($text),
                'tts'  => htmlspecialchars_decode($text),
                'buttons' => [],
                //'end_session' => $this->end_session,
                'end_session' => true,
            ],
            'session' => [
                'session_id' => $apiRequestArray['session']['session_id'],
                'message_id' => $apiRequestArray['session']['message_id'],
                'user_id' => $apiRequestArray['session']['user_id'],
            ],
            'version' => $apiRequestArray['version']
        ];

        return response()->json($arrayToEncode);

    }

    /**
     * обработчик присылаемых команд
     *
     * @param $apiRequestArray
     * @return mixed
     */
    private function process($apiRequestArray)
    {

        if($this->secure($apiRequestArray) === false){
            return 'Вы не зарегистрированный пользователь.';
        }

        $tokens = $apiRequestArray['request']['nlu']['tokens'];
        $commands = [
            'привет'  => 'hello',
            'ping'    => 'ping',

            'статус'  => 'state',

            'авария'  => 'alarmOn',

            'сенсоры' => 'state',
            'сенсор'  => 'state',
            'полив'   => 'state',
            'клапаны' => 'state',
            'клапан'  => 'state',

            'планировщик' => 'scheduler',

            'погода'  => 'weather',

            'шланг'   => 'hose',

            //'самоуничтожение' => 'destroy', // уничтожить данные и заморозить сервисы, отключить всю электронику
            'заблокировать' => 'blocking',  // заблокировать всех пользователей, на случай взлома
            //'резервная' => 'backup',        // резервная копия базы

            'свет'       => 'lamp',
            'лампа'      => 'lamp',
            'лампу'      => 'lamp',
            'освещение'  => 'lamp',
        ];

        foreach ($commands as $key=>$value) {
            if(in_array($key, $tokens)) {
                $tokens = $this->delete_value_in_array($key, $tokens);
                return $this->$value($tokens);
            }
        }

        return $this->error();

    }

    /**
     * Приветствие
     *
     * @param $text
     * @return string
     */
    private function hello($text): string
    {
        return 'Привет, я уберсервер, я обслуживаю этот дом. Благодаря мне он становится умным.Мне можно сказать: 
        Привет, статус сенсоров, статус полива, общий статус, запусти планировщик, стоп или авария для аварийного 
        выключения автополива. Включи шланг.';

    }

    /**
     * Яндекс раз в пол минуты пингует навык, сцуко. Для уменьшения нагрузки на серв сделал специальную заглушку
     *
     * @param $text
     * @return string
     */
    private function ping($text): string
    {
        $this->end_session = true;
        return 'pong';

    }

    /**
     * Статус сенсоров и полива
     *
     * @param $text
     * @return string
     */
    private function state($tokens): string
    {
        $alice = new Alice();
        if(
            in_array('сенсор', $tokens) ||
            in_array('сенсоры', $tokens) ||
            in_array('сенсоров', $tokens) ||
            in_array('датчик', $tokens) ||
            in_array('датчики', $tokens) ||
            in_array('датчиков', $tokens)
        ) {

            return $alice->stateSensors();
        }

        if(
            in_array('полив', $tokens) ||
            in_array('полива', $tokens) ||
            in_array('клапан', $tokens) ||
            in_array('клапаны', $tokens) ||
            in_array('клапанов', $tokens)
        ) {
            return $alice->stateSmartWatering();
        }

        if( in_array('общий', $tokens) ) {
            return $alice->stateAll();
        }

        return 'Скажи "Статус сенсоров" или "Статус полива"?';

    }

    /**
     * Планировщик задач, сейчас это только автополив (вызов - для активации задания)
     *
     * @param $tokens
     * @return string
     */
    private function scheduler($tokens): string
    {
        $alice = new Alice();
        if(
            in_array('запуск', $tokens) ||
            in_array('старт', $tokens) ||
            in_array('запустить', $tokens) ||
            in_array('стартовать', $tokens)
        ) {
            return $alice->startScheduleWatering();
        }

        if(
            in_array('стоп', $tokens) ||
            in_array('останови', $tokens) ||
            in_array('останов', $tokens)
        ) {
            return $alice->stopScheduleWatering();
        }
        $this->end_session = true;
        return 'Уточните, сказав: "Планировщик старт" или "Планировщик стоп"';

    }

    /**
     * Аварийный останов полива
     *
     * @param $tokens
     * @return string
     */
    private function alarmOn($tokens): string
    {
        $this->end_session = true;
        $alice = new Alice();
        return $alice->alarmOn();

    }

    /**
     * Вывод погоды из базы данных
     *
     * @param $tokens
     * @return string
     */
    private function weather($tokens): string
    {
        $this->end_session = true;
        return Weather::getWeather();

    }

    /**
     * Активация шланга - муфта со шлангом подсоединена к главному клапану, при его активации появится давление в ветке
     * со шлангом
     *
     * @param $tokens
     * @return string
     */
    private function hose($tokens): string
    {
        $alice = new Alice();
        if(
            in_array('запуск', $tokens) ||
            in_array('старт', $tokens) ||
            in_array('запустить', $tokens) ||
            in_array('стартовать', $tokens)
        ) {
            if($this->isAdmin) {
                return $alice->hoseOn();
            }

            return 'У вас нет прав на это действие';
        }

        if(
            in_array('стоп', $tokens) ||
            in_array('останови', $tokens) ||
            in_array('останов', $tokens) ||
            in_array('выключить', $tokens) ||
            in_array('выключи', $tokens)
        ) {
            return $alice->hoseOff();
        }

        if($this->isAdmin) {
            return $alice->hoseOn();
        }

        $this->end_session = true;
        return 'У вас нет прав на это действие';

    }

    /**
     * Включение светильника в пристройке. Первый опыт с освещением. Я такое не приветствую, больше сложностей
     *
     * @param $tokens
     * @return string
     */
    private function lamp($tokens): string
    {
        $alice = new Alice();
        if(
            in_array('включи', $tokens) ||
            in_array('включить', $tokens)

        ) {
            $this->end_session = true;
            return $alice->lampOn();
        }

        if(
            in_array('выключить', $tokens) ||
            in_array('выключи', $tokens)
        ) {
            $this->end_session = true;
            return $alice->lampOff();
        }

//        if($this->isAdmin) {
//            return $alice->hoseOn();
//        }

        $this->end_session = true;
        return 'У вас нет прав на это действие';

    }

    /**
     * При нераспознаной команде вывести фразу "Ошибка.
     *
     * @param $text
     * @return string
     */
    private function error(): string
    {
        return 'Не известный запрос';
    }

    /**
     * Удаляет значение из массива
     *
     * @param $value
     * @param $array
     * @return mixed
     */
    private function delete_value_in_array($value, $array)
    {
        if ( (($key = array_search($value, $array)) !== false) && (count($array) > 1) ) {
            unset($array[$key]);
        }

        return $array;

    }

    /**
     * Сохранение всех диалогов и проверка на зарегистрированных пользователей
     *
     * @param $apiRequestArray
     * @var Alice $assistant
     * @return bool
     */
    private function secure($apiRequestArray)
    {
        if(
            $apiRequestArray['request']['command'] == 'ping' ||
            $apiRequestArray['request']['command'] == null
        ) {
            return true;
        }

        $assistant = new Alice();
        $assistant->saveDialog($apiRequestArray);

        $tokens = $apiRequestArray['request']['nlu']['tokens'];
        $userId = var_export($apiRequestArray['session']['user_id'], true);
        if(
            in_array('регистрация', $tokens) ||
            in_array('зарегистрировать', $tokens)
        ) {
            $security = new AliceSecure();
            $security->registerUser($userId);
            return true;
        }
        $this->validUser = AliceSecure::validateUser($userId);
        $this->isAdmin   = AliceSecure::isAdmin($userId);
        $this->user_id   = $userId;

        return $this->validUser;

    }

//    /**
//     * Уничтожение данных и отключение всех сенсоров
//     *
//     * @param $apiRequestArray
//     * @return string
//     */
    /*
    private function destroy($apiRequestArray): string
    {
        $aliceSecure = new AliceSecure();
        $aliceSecure->destroy();
        return 'destroy - is set...';

    }
    */

//    /**
//     * Заблокировать всех зарегистрированных пользователей
//     *
//     * @param $apiRequestArray
//     * @return string
//     */
//    private function blocking($apiRequestArray): string
//    {
//        $aliceSecure = new AliceSecure();
//        $aliceSecure->blocking();
//        return 'blocking - ok';
//
//    }

//    /**
//     * Команда для создания резерной копии проекта
//     *
//     * @param $apiRequestArray
//     * @return string
//     */
//    private function backup($apiRequestArray): string
//    {
//        $aliceSecure = new AliceSecure();
//        $aliceSecure->backup();
//        return 'backup - ok';
//
//    }

}
