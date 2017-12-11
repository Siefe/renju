<?php
/**
 * Created by PhpStorm.
 * User: user
 * Date: 11/8/2017
 * Time: 17:51
 */

namespace frontend\modules\games\controllers;


use common\components\BoardTool;
use common\components\ForbiddenPointFinder;
use common\components\Gateway;
use common\components\MsgHelper;
use common\models\Games;
use common\models\GameUndoLog;
use common\models\Player;
use common\services\GameService;
use common\services\UserService;
use frontend\components\Controller;
use yii\web\HttpException;

class GamesController extends Controller
{
    /**
     * 展示对局网页。
     * @return string
     * @throws HttpException
     */
    public function actionGame()
    {
        $game_id = intval($this->get('id'));
        $game = Games::findOne($game_id);
        //TODO 显示正确的错误页。
        if(!$game)
        {
            throw new HttpException(404);
        }
        return $this->render('game',[
            'game' => GameService::renderGame($game_id),
            'ws_token' => GameService::newToken(),
            'userinfo' => $this->_user() ? UserService::renderUser($this->_user()->id ) : null
        ]);
    }

    /**
     * 输入五手打点数量
     */
    public function actionA5_number()
    {
        $number = abs(intval($this->post('number')));
        $game_id = intval($this->post('game_id'));
        if(!$number)
        {
            return $this->renderJSON([],'填写的打点数不正确',-1);
        }

        if(!$this->_user())
        {
            return $this->renderJSON([],'您尚未登录',-1);
        }
        $game_info = GameService::renderGame($game_id);
        if(!$game_info)
        {
            return $this->renderJSON([],'棋局不存在',-1);
        }
        if($game_info['whom_to_play'] != $this->_user()->id)
        {
            return $this->renderJSON([],'当前不轮到您下棋',-1);
        }
        if($game_info['a5_numbers'] > 0)
        {
            return $this->renderJSON([],'当前不是指定打点的回合',-1);
        }

        $stones = strlen($game_info['game_record'])/2;
        $game_object = Games::findOne($game_id);
        switch ($game_object->rule)
        {
            case 'Yamaguchi':
                if($stones == 3)
                {
                    $game_object->a5_numbers = min($number,12);
                }
                break;
            case 'Soosyrv8':
                if($stones == 4)
                {
                    $game_object->a5_numbers = min($number,8);
                }
                break;
        }
        if($game_object->a5_numbers > 0)
        {
            $game_object->offer_draw = 0;
            $game_object->movetime = date('Y-m-d H:i:s');
            $game_object->save(0);
            Gateway::sendToGroup($game_id,MsgHelper::build('game_info',[
                'game' => GameService::renderGame($game_id)
            ]));
            Gateway::sendToGroup($game_id,MsgHelper::build('notice',[
                'content' => "打点数设置为" . $game_object->a5_numbers . '。'
            ]));
            return $this->renderJSON([]);
        }
        else
        {
            return $this->renderJSON([],'指定打点数量失败',-1);
        }
    }

    //TODO 研究一下怎么引入transaction，棋局的计时、计算输赢需要事务处理，冲突了就跪了
    /**
     * 落子
     */
    public function actionPlay()
    {
        if(!$this->_user())
        {
            return $this->renderJSON([],'您尚未登录',-1);
        }
        $game_id = intval($this->post("game_id"));
        $coordinate = trim($this->post('coordinate'));
        $game_info = GameService::renderGame($game_id);
        if(!$game_info)
        {
            return $this->renderJSON([],'棋局不存在',-1);
        }
        if($game_info['whom_to_play'] != $this->_user()->id)
        {
            return $this->renderJSON([],'当前不轮到您下棋',-1);
        }
        $stones = strlen($game_info['game_record'])/2;
        //特殊情况判断：如果是轮到输入打点的，提示玩家输入打点数目，而不是落子。在这时走进此逻辑的都不予执行，做个提示。
        if($game_info['a5_numbers'] == 0 && (($game_info['rule'] == 'Yamaguchi' && $stones == 3) || ($game_info['rule'] == 'Soosyrv8' && $stones == 4)))
        {
            return $this->renderJSON([],'当前请填写五手打点数量',-1);
        }
        //到这里，可以落子了。
        //是不是在下打点？
        $game_object = Games::findOne($game_id);
        //提和id设置为0
        if($game_info['free_opening'] == 0)
        {
            if(($stones == 0 && $coordinate != '88') || ($stones == 1 && (!in_array($coordinate{0},[7,8,9]) || !in_array($coordinate{1},[7,8,9])) ) || ($stones == 2 && (!in_array($coordinate{0},[6,7,8,9,'a']) || !in_array($coordinate{1},[6,7,8,9,'a']))) )
            {
                return $this->renderJSON([],'标准开局不允许26种开局以外的开局',-1);
            }
        }
        $game_object->offer_draw = 0;
        if($stones == 4 && in_array($game_object->rule,['Yamaguchi','RIF','Soosyrv8']))
        {
            //第五手，有2种情况，都很特殊
            $a5_on_board = strlen($game_object->a5_pos)/2;
            if($a5_on_board < $game_object->a5_numbers)
            {
                //落子进a5_pos
                if(!BoardTool::board_correct($game_object->game_record . $game_object->a5_pos . $coordinate))
                {
                    return $this->renderJSON([],'您提交的数据不正确，请刷新页面重试。',-1);
                }
                if(BoardTool::a5_symmetry($game_object->game_record,$game_object->a5_pos . $coordinate))
                {
                    return $this->renderJSON([],'五手打点不可以对称，请重新选择。',-1);
                }
                $game_object->a5_pos = $game_object->a5_pos . $coordinate;
                $game_object->movetime = date('Y-m-d H:i:s');
                $game_object->save(0);
            }
            else
            {
                //这是在选黑5，只能是在a5_pos范围内选点。
                if(!in_array($coordinate, str_split($game_object->a5_pos,2)))
                {
                    return $this->renderJSON([],'请选择黑方的打点。',-1);
                }
                $game_object->game_record = $game_object->game_record . $coordinate;
                $game_object->movetime = date('Y-m-d H:i:s');
                $game_object->save(0);
            }
        }
        else
        {
            if(!BoardTool::board_correct($game_object->game_record . $coordinate))
            {
                return $this->renderJSON([],'您提交的数据不正确，请刷新页面重试。',-1);
            }
            $old_board = $game_object->game_record;

            $game_object->game_record = $game_object->game_record . $coordinate;
            $game_object->movetime = date('Y-m-d H:i:s');
            $game_object->save(0);

            $checkwin = new ForbiddenPointFinder($old_board);
            $result = $game_object->rule == 'Gomoku' ? $checkwin->GomokuCheckWin($coordinate) : $checkwin->CheckWin($coordinate);
            if($result == BLACKFIVE)
            {
                //黑胜
                BoardTool::do_over($game_id,1,false);
                Gateway::sendToGroup($game_id,MsgHelper::build('game_over',[
                    'content' => "黑方获胜"
                ]));
            }
            elseif($result == WHITEFIVE || $result == BLACKFORBIDDEN)
            {
                //白胜
                BoardTool::do_over($game_id,0,false);
                Gateway::sendToGroup($game_id,MsgHelper::build('game_over',[
                    'content' => ($result == WHITEFIVE ? "连五" : "黑方禁手") . "，白方获胜"
                ]));
            }
            elseif($stones == 225)
            {
                //和棋
                BoardTool::do_over($game_id,0.5,false);
                Gateway::sendToGroup($game_id,MsgHelper::build('game_over',[
                    'content' => "满局，和棋。"
                ]));
            }
        }
        Gateway::sendToGroup($game_id,MsgHelper::build('game_info',[
            'game' => GameService::renderGame($game_id)
        ]));
        GameService::sendGamesList();

        return $this->renderJSON([]);
    }

    /**
     * 交换
     */
    public function actionSwap()
    {
        $game_id = intval($this->post('game_id'));

        if(!$this->_user())
        {
            return $this->renderJSON([],'您尚未登录',-1);
        }
        $game_info = GameService::renderGame($game_id);
        if(!$game_info)
        {
            return $this->renderJSON([],'棋局不存在',-1);
        }
        if($game_info['whom_to_play'] != $this->_user()->id)
        {
            return $this->renderJSON([],'当前不轮到您下棋',-1);
        }

        $stones = strlen($game_info['game_record'])/2;
        $allow_swap = false;
        $game_object = Games::findOne($game_id);
        switch ($game_object->rule)
        {
            case 'RIF':
            case 'Yamaguchi':
                if($stones == 3 && $game_object->a5_numbers > 0 && $game_object->swap == 0)
                {
                    $allow_swap = true;
                }
                break;
            case 'Soosyrv8':
                if($stones == 3 && $game_object->swap == 0)
                {
                    $allow_swap = true;
                }
                elseif ($stones == 4 && $game_object->a5_numbers > 0 && $game_object->a5_pos == '' && $game_object->soosyrv_swap == 0)
                {
                    $allow_swap = true;
                }
                break;
        }
        if($allow_swap)
        {
            $game_object->offer_draw = 0;
            $game_object->black_id = $game_info['white_id'];
            $game_object->white_id = $game_info['black_id'];
            $game_object->black_time = $game_info['white_time'];
            $game_object->white_time = $game_info['black_time'];
            if($stones == 3)
            {
                $game_object->swap = 1;
            }
            else
            {
                $game_object->soosyrv_swap = 1;
            }
            $game_object->movetime = date('Y-m-d H:i:s');
            $game_object->save(0);
            Gateway::sendToGroup($game_id,MsgHelper::build('game_info',[
                'game' => GameService::renderGame($game_id)
            ]));
            Gateway::sendToGroup($game_id,MsgHelper::build('notice',[
                'content' => "双方先后手已交换"
            ]));
            return $this->renderJSON([]);
        }
        else
        {
            return $this->renderJSON([],'指定打点数量失败',-1);
        }
    }

    /**
     * 提和、同意
     */
    public function actionOffer_draw()
    {
        $game_id = intval($this->post('game_id'));

        if(!$this->_user())
        {
            return $this->renderJSON([],'您尚未登录',-1);
        }
        $game_info = GameService::renderGame($game_id);
        if(!$game_info)
        {
            return $this->renderJSON([],'棋局不存在',-1);
        }
        if($game_info['black_id'] != $this->_user()->id && $game_info['white_id'] != $this->_user()->id)
        {
            return $this->renderJSON([],'这不是您的对局',-1);
        }
        $opponent_id = $this->_user()->id == $game_info['black_id'] ? $game_info['white_id'] : $game_info['black_id'];

        if($game_info['status'] != GameService::PLAYING)
        {
            return $this->renderJSON([],'棋局不是对局状态，不能进行操作。',-1);
        }

        $game_object = Games::findOne($game_id);
        if($game_object->offer_draw == 0)
        {
            $game_object->offer_draw = $this->_user()->id;
            $game_object->movetime = date('Y-m-d H:i:s');
            $game_object->save(0);
            Gateway::sendToGroup($game_id,MsgHelper::build('game_info',[
                'game' => GameService::renderGame($game_id)
            ]));
            Gateway::sendToGroup($game_id,MsgHelper::build('notice',[
                'content' => $this->_user()->nickname. "提出和棋"
            ]));
            return $this->renderJSON([]);
        }
        elseif ($game_object->offer_draw == $this->_user()->id)
        {
            return $this->renderJSON([],'您已经提和了，请等待对方回应',-1);
        }
        elseif ($game_object->offer_draw == $opponent_id)
        {
            BoardTool::do_over($game_id,0.5);
            Gateway::sendToGroup($game_id,MsgHelper::build('game_over',[
                'content' => $this->_user()->nickname. "同意和棋，对局结束。"
            ]));
            return $this->renderJSON([]);
        }
        else
        {
            return $this->renderJSON([],'发生错误，请联系管理员',-1);
        }
    }

    /**
     * 认输
     */
    public function actionResign()
    {
        $game_id = intval($this->post('game_id'));

        if(!$this->_user())
        {
            return $this->renderJSON([],'您尚未登录',-1);
        }
        $game_info = GameService::renderGame($game_id);
        if(!$game_info)
        {
            return $this->renderJSON([],'棋局不存在',-1);
        }
        if($game_info['black_id'] != $this->_user()->id && $game_info['white_id'] != $this->_user()->id)
        {
            return $this->renderJSON([],'这不是您的对局',-1);
        }

        if($game_info['status'] != GameService::PLAYING)
        {
            return $this->renderJSON([],'棋局不是对局状态，不能进行操作。',-1);
        }

        $game_result = $this->_user()->id == $game_info['black_id'] ? 0 : 1 ;//黑认输则白胜
        BoardTool::do_over($game_id,$game_result);
        Gateway::sendToGroup($game_id,MsgHelper::build('game_over',[
            'content' => ($game_result ? "白":"黑") . "方认输。"
        ]));
        return $this->renderJSON([]);
    }

    /**
     * 提出悔棋申请
     * 悔棋申请提出时 记录当前局面，提出者id，时间，回到第几手。
     * 不能提出涉及前5手的悔棋。
     * render棋局时，如果是正在进行的棋局，则先update悔棋记录，检查状态0的悔棋申请，和当前盘面不一致的全部-1掉。 将最新的有效的悔棋申请附在数据结构里。
     * 同意：验证盘面与申请时一致，然后恢复到指定手数，render，然后发广播通知。
     * 同意的话，可以获得10%时间的补偿。
     * 终局时清理所有未同意的悔棋申请。
     */
    public function actionUndo_create()
    {
        $game_id = intval($this->post('game_id'));
        //悔棋到第几手。 最终会保留前$to_step - 1手
        $to_step = intval($this->post('to_step'));
        $comment = trim($this->post('comment'));

        if(!$this->_user())
        {
            return $this->renderJSON([],'您尚未登录',-1);
        }
        $game_info = GameService::renderGame($game_id);
        if(!$game_info)
        {
            return $this->renderJSON([],'棋局不存在',-1);
        }
        if($game_info['black_id'] != $this->_user()->id && $game_info['white_id'] != $this->_user()->id)
        {
            return $this->renderJSON([],'这不是您的对局',-1);
        }

        if($game_info['status'] != GameService::PLAYING)
        {
            return $this->renderJSON([],'棋局不是对局状态，不能进行操作。',-1);
        }
        if($to_step <= 5)
        {
            return $this->renderJSON([],'最多只允许悔棋到第六手',-1);
        }
        if(strlen($game_info['game_record']) / 2 <= $to_step)
        {
            return $this->renderJSON([],'悔棋步数超出了当前棋局的范围',-1);
        }

        GameUndoLog::updateAll(['status' => -1],['game_id' => $game_id,'status' => 0,]);

        $undo = new GameUndoLog();
        $undo->game_id = $game_id;
        $undo->uid = $this->_user()->id;
        $undo->current_board = $game_info['game_record'];
        $undo->to_number = $to_step;
        $undo->comment = $comment;
        $undo->status = 0;
        $undo->created_time = date('Y-m-d H:i:s');
        $undo->save(0);

        Gateway::sendToUid(($game_info['black_id'] == $this->_user()->id ? $game_info['white_id'] : $game_info['black_id']),MsgHelper::build('undo',[
            'undo_id' => $undo->id,
        ]));
        return $this->renderJSON([],'悔棋申请成功');

    }

    public function actionUndo_accept()
    {
        $undo_id = intval($this->post('undo_id'));
/*
        if(!$this->_user())
        {
            return $this->renderJSON([],'您尚未登录',-1);
        }
        $game_info = GameService::renderGame($game_id);
        if(!$game_info)
        {
            return $this->renderJSON([],'棋局不存在',-1);
        }
        if($game_info['black_id'] != $this->_user()->id && $game_info['white_id'] != $this->_user()->id)
        {
            return $this->renderJSON([],'这不是您的对局',-1);
        }

        if($game_info['status'] != GameService::PLAYING)
        {
            return $this->renderJSON([],'棋局不是对局状态，不能进行操作。',-1);
        }
*/

    }

    public function actionTimeout()
    {
        $game_id = intval($this->post('game_id'));
        if(!$game_id)
        {
            return $this->renderJSON([],'指定游戏不存在');
        }
        $cache_key = sprintf("timeout_lock_game%d",$game_id);
        $my_rand = rand(10000,99999);
        $lock = \Yii::$app->redis->setNx($cache_key,$my_rand);
        //采用setNx存一个数字进去，如果存成功了，而且
        if($lock && \Yii::$app->redis->get($cache_key) == $my_rand)
        {
            GameService::renderGame($game_id);
            \Yii::$app->redis->setTimeout($cache_key,10);
            return $this->renderJSON([],'done');
        }
        return $this->renderJSON([],'thanks');
    }

    public function actionInfo()
    {
        $game_id = intval($this->get('id'));
        if(!$game_id)
        {
            return $this->renderJSON([],'指定游戏不存在');
        }
        return $this->renderJSON(['game' => GameService::renderGame($game_id)]);
    }
    /**
     * 一个演示板，用于教学、沟通；
     * 就是一个不判断胜负的没有时间限制的演示功能；新建者可落子，可授权给他人落子。
     */
    public function actionPlay_board()
    {

    }

    public function actionHistory()
    {
        $per_page = 12;
        $player_id = intval($this->get('player_id'));
        $player = UserService::renderUser($player_id);
        if(!$player)
        {
            return $this->redirect('/');
        }
        if(\Yii::$app->request->isAjax)
        {
            $page = intval($this->get('page',1));
            $games = Games::find()
                ->select(['id','black_id','white_id','game_record','status','rule','comment'])
                ->where("black_id={$player_id} or white_id={$player_id}")
                ->asArray()
                ->limit($per_page + 1)
                ->offset($per_page * ($page - 1))
                ->orderBy('id desc')
                ->all();
            $has_next = count($games) > $per_page ;
            if($has_next)
            {
                unset($games[$per_page]);
            }
            UserService::render($games,'black_id','black');
            UserService::render($games,'white_id','white');
            return $this->renderJSON([
                'games' => $games,
                'has_next' => $has_next
            ]);
        }
        else
        {
            return $this->render("history",['player' => $player]);
        }
    }
}