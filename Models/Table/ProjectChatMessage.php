<?php

namespace Models\Table {

    use Core\AbstractEntity;
    use Helpers\Validator;
    use Interfaces\EntityInterface;
    use Interfaces\ModelInterface;
    use Traits\Model;

    /**
     * @property int    $id
     * @property string $date_create
     * @property int    $user_id
     * @property int    $project_id
     * @property int    $lang_id
     * @property string $message
     * @property int    $session_id
     */
    class ProjectChatMessage extends AbstractEntity implements EntityInterface, ModelInterface {
        use Model;

        private static $table = 'message';

        protected $data;

        protected static
            $defaults = null,
            $properties = [
                'id'          => [self::TYPE_INT,      [Validator::MIN => 1]],
                'date_create' => [self::TYPE_DATETIME, []],
                'user_id'     => [self::TYPE_INT,      [], self::TYPE_NULLABLE],
                'project_id'  => [self::TYPE_INT,      []],
                'lang_id'     => [self::TYPE_INT,      [Validator::MIN => 1]],
                'message'     => [self::TYPE_STRING,   [Validator::MIN => 1]],
                'session_id'  => [self::TYPE_INT,      [Validator::MIN => 1]],
            ];
    }
}