<?php
/**
 * Get emails in your app with cake like finds.
 *
 * Copyright (c) 2010 Carl Sutton ( dogmatic69 )
 * Copyright (c) 2011 Kevin van Zonneveld ( kvz )
 *
 * @filesource
 * @copyright Copyright (c) 2010 Carl Sutton ( dogmatic69 )
 * @copyright Copyright (c) 2011 Kevin van Zonneveld ( kvz )
 * @link http://www.infinitas-cms.org
 * @link https://github.com/kvz/cakephp-emails-plugin
 * @package libs
 * @subpackage libs.models.datasources.reader
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 * @since 0.9a
 *
 * @author dogmatic69
 * @author kvz
 *
 * Modifications since 0.8a (when code was stripped from Infinitas):
 *   https://github.com/kvz/cakephp-emails-plugin/compare/10767bee59dd425ced5b97ae9604acf7f3c0d27a...master
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 */
class ImapSource extends DataSource {
    protected $_isConnected     = false;
    protected $_connectionString = null;
    protected $_connectionType   = '';
    
    protected $_defaultConfigs = array(
        'global' => array(
            'username' => false,
            'password' => false,
            'email' => false,
            'server' => 'localhost',
            'type' => 'imap',
            'ssl' => false,
            'retry' => 3,
            'error_handler' => 'php',
            'auto_mark_as' => array('seen'),
        ),
        'imap' => array(
            'port' => 143,
        ),
        'pop3' => array(
            'port' => 110,
        ),
    );

    public $marks = array(
        '\Seen',
        '\Answered',
        '\Flagged',
        '\Deleted',
        '\Draft',
    );

    public $config = array();
    public $driver = null;

    /**
     * Default array of field list for imap mailbox.
     *
     * @var array
     */
    protected $_schema = array(
        'id' => array('type' => 'integer', 'default' => NULL, 'length' => 15, 'key' => 'primary',),
        'message_id' => array('type' => 'string', 'default' => NULL, 'length' => 255,),
        'email_number' => array('type' => 'integer', 'default' => NULL, 'length' => 15,),

        'to' => array('type' => 'string', 'default' => NULL, 'length' => 255,),
        'to_name' => array('type' => 'string', 'default' => NULL, 'length' => 255,),
        'from' => array('type' => 'string', 'default' => NULL, 'length' => 255,),
        'from_name' => array('type' => 'string', 'default' => NULL, 'length' => 255,),
        'reply_to' => array('type' => 'string', 'default' => NULL, 'length' => 255,),
        'reply_to_name' => array('type' => 'string', 'default' => NULL, 'length' => 255,),
        'sender' => array('type' => 'string', 'default' => NULL, 'length' => 255,),
        'sender_name' => array('type' => 'string', 'default' => NULL, 'length' => 255,),

        'subject' => array('type' => 'string', 'default' => NULL, 'length' => 255,),
        'slug' => array('type' => 'string', 'default' => NULL, 'length' => 255,),
        'body' => array('type' => 'text', 'default' => NULL,),
        'plainmsg' => array('type' => 'text', 'default' => NULL,),
        'size' => array('type' => 'string', 'default' => NULL, 'length' => 255,),

        'recent' => array('type' => 'boolean', 'default' => NULL, 'length' => 1,),
        'seen' => array('type' => 'boolean', 'default' => NULL, 'length' => 1,),
        'flagged' => array('type' => 'boolean', 'default' => NULL, 'length' => 1,),
        'answered' => array('type' => 'boolean', 'default' => NULL, 'length' => 1,),
        'draft' => array('type' => 'boolean', 'default' => NULL, 'length' => 1,),
        'deleted' => array('type' => 'boolean', 'default' => NULL, 'length' => 1,),

        'thread_count' => array('type' => 'integer', 'default' => NULL, 'length' => 15, 'key' => 'primary',),
        'attachments' => array('type' => 'text', 'default' => NULL,),
        'in_reply_to' => array('type' => 'string', 'default' => NULL, 'length' => 255,),
        'reference' => array('type' => 'string', 'default' => NULL, 'length' => 255,),
        'new' => array('type' => 'boolean', 'default' => NULL, 'length' => 1,),
        'created' => array('type' => 'datetime', 'default' => NULL,),
    );

    public $columns = array(
        'primary_key' => array('name' => 'NOT NULL AUTO_INCREMENT'),
        'string' => array('name' => 'varchar', 'limit' => '255'),
        'text' => array('name' => 'text'),
        'integer' => array('name' => 'int', 'limit' => '11', 'formatter' => 'intval'),
        'float' => array('name' => 'float', 'formatter' => 'floatval'),
        'datetime' => array('name' => 'datetime', 'format' => 'Y-m-d H:i:s', 'formatter' => 'date'),
        'timestamp' => array('name' => 'timestamp', 'format' => 'Y-m-d H:i:s', 'formatter' => 'date'),
        'time' => array('name' => 'time', 'format' => 'H:i:s', 'formatter' => 'date'),
        'date' => array('name' => 'date', 'format' => 'Y-m-d', 'formatter' => 'date'),
        'binary' => array('name' => 'blob'),
        'boolean' => array('name' => 'tinyint', 'limit' => '1')
    );


    /**
     * __construct()
     *
     * @param mixed $config
     */
    public function __construct ($config) {
        parent::__construct($config);

        if (!isset($config['type'])) {
            $type = $this->_defaultConfigs['global']['type'];
        } else {
            $type = $config['type'];
        }
        $newConfig = array_merge($this->_defaultConfigs['global'], $this->_defaultConfigs[$type], $this->config);
        $newConfig['email'] = !empty($newConfig['email']) ? $newConfig['email'] : $newConfig['username'];

        $this->config = $newConfig;
    }

    /**
     * Expunge messages marked for deletion
     */
    public function __destruct () {
        if ($this->_isConnected && $this->Stream) {
            $this->_isConnected = false;
            // If set to CL_EXPUNGE, the function will silently expunge the
            // mailbox before closing, removing all messages marked for deletion.
            // You can achieve the same thing by using imap_expunge()
            imap_close($this->Stream, CL_EXPUNGE);
        }
    }

    /**
     * describe the data
     *
     * @param mixed $Model
     * @return array the shcema of the model
     */
    public function describe ($Model) {
        return $this->_schema;
    }

    /**
     * listSources
     *
     * list the sources???
     *
     * @return array sources
     */
    public function listSources () {
        return array('listSources');
    }

    /**
     * Returns a query condition, or null if it wasn't found
     *
     * @param object $Model
     * @param array  $query
     * @param string $field
     * 
     * @return mixed or null
     */
    protected function _cond ($Model, $query, $field) {
        $keys = array(
            '`' . $Model->alias . '`.`' . $field . '`',
            $Model->alias . '.' . $field,
            $field,
        );

        foreach ($keys as $key) {
            if (array_key_exists($key, @$query['conditions'])) {
                return $query['conditions'][$key];
            }
        }

        return null;
    }

    /**
     * Returns ids from searchCriteria or false if there's other criteria involved
     *
     * @param array $searchCriteria
     *
     * @return false or array
     */
    protected function _uidsByCriteria ($searchCriteria) {
        if (is_numeric($searchCriteria) || Set::numeric($searchCriteria)) {
            // We already know the id, or list of ids
            $results = $searchCriteria;
            if (!is_array($results)) {
                $results = array($results);
            }
            return $results;
        }

        return false;
    }

    /**
     * Tranform search criteria from CakePHP -> Imap
     * Does AND, not OR
     *
     * Supported:
     *  FROM "string" - match messages with "string" in the From: field
     *
     *  ANSWERED - match messages with the \\ANSWERED flag set
     *  UNANSWERED - match messages that have not been answered
     *
     *  SEEN - match messages that have been read (the \\SEEN flag is set)
     *  UNSEEN - match messages which have not been read yet
     *
     *  DELETED - match deleted messages
     *  UNDELETED - match messages that are not deleted
     *
     *  FLAGGED - match messages with the \\FLAGGED (sometimes referred to as Important or Urgent) flag set
     *  UNFLAGGED - match messages that are not flagged
     *
     *  RECENT - match messages with the \\RECENT flag set
     * 
     * @todo: 
     *  A string, delimited by spaces, in which the following keywords are allowed. Any multi-word arguments (e.g. FROM "joey smith") must be quoted.
     *  ALL - return all messages matching the rest of the criteria
     *  BCC "string" - match messages with "string" in the Bcc: field
     *  BEFORE "date" - match messages with Date: before "date"
     *  BODY "string" - match messages with "string" in the body of the message
     *  CC "string" - match messages with "string" in the Cc: field
     *  KEYWORD "string" - match messages with "string" as a keyword
     *  NEW - match new messages
     *  OLD - match old messages
     *  ON "date" - match messages with Date: matching "date"
     *  SINCE "date" - match messages with Date: after "date"
     *  SUBJECT "string" - match messages with "string" in the Subject:
     *  TEXT "string" - match messages with text "string"
     *  TO "string" - match messages with "string" in the To:
     *  UNKEYWORD "string" - match messages that do not have the keyword "string"
     *
     * @param object $Model
     * @param array  $query
     *
     * @return array
     */
    protected function _makeSearch ($Model, $query) {
        $searchCriteria = array();

        if (!@$query['conditions']) {
            $query['conditions'] = array();
        }


        // Special case. When somebody specifies primaryKey(s),
        // We don't have to do an actual search
        if (($id = $this->_cond($Model, $query, $Model->primaryKey))) {
            return $this->_toUid($id);
        }

        // Flag search parameters
        $flags = array(
            'recent',
            'seen',
            'flagged',
            'answered',
            'draft',
            'deleted',
        );

        foreach ($flags as $flag) {
            if (null !== ($val = $this->_cond($Model, $query, $flag))) {
                $upper   = strtoupper($flag);
                $unupper = 'UN' . $upper;

                if (!$val && ($flag === 'recent')) {
                    // There is no UNRECENT :/
                    // Just don't set the condition
                    continue;
                }

                $searchCriteria[] = $val ? $upper : $unupper;
            }
        }

        // Strin search parameters
        if (($val = $this->_cond($Model, $query, 'from'))) {
            $searchCriteria[] = 'FROM "' . $val . '"';
        }

        return $searchCriteria;
    }

    /**
     * Tranform order criteria from CakePHP -> Imap
     *
     * For now always sorts on date descending.
     * @todo: Support the following sort parameters:
     *  SORTDATE - message Date
     *  SORTARRIVAL - arrival date
     *  SORTFROM - mailbox in first From address
     *  SORTSUBJECT - message subject
     *  SORTTO - mailbox in first To address
     *  SORTCC - mailbox in first cc address
     *  SORTSIZE - size of message in octets
     *
     * @param object $Model
     * @param array  $query
     * 
     * @return array
     */
    protected function _makeOrder($Model, $query) {

        // Tranform order criteria
        $orderReverse  = 1;
        $orderCriteria = SORTDATE;

        return array($orderReverse, $orderCriteria);
    }

    public function delete ($Model, $conditions = null) {
        $query          = compact('conditions');
        $searchCriteria = $this->_makeSearch($Model, $query);
        $uids           = $this->_uidsByCriteria($searchCriteria);
        if ($uids === false) {
            $uids = $Model->find('list', $query);
        }

        // Nothing was found
        if (empty($uids)) {
            return false;
        }

        $success = true;
        foreach ($uids as $uid) {
            if (!imap_delete($this->Stream, $uid, FT_UID)) {
                $this->err('Unable to delete email with uid: %s', $uid);
                $success = false;
            }
        }

        return $success;
    }

    /**
     * read data
     *
     * this is the main method that reads data from the datasource and
     * formats it according to the request from the model.
     *
     * @param mixed $model the model that is requesting data
     * @param mixed $query the qurey that was sent
     *
     * @return the data requested by the model
     */
    public function read ($Model, $query) {
        if (!$this->connect($Model, $query)) {
            return $this->err($Model, 'Cannot connect to server');
        }

        $searchCriteria = $this->_makeSearch($Model, $query);
        $uids           = $this->_uidsByCriteria($searchCriteria);
        if ($uids === false) {
            // Perform Search & Order. Returns list of ids
            list($orderReverse, $orderCriteria) = $this->_makeOrder($Model, $query);
            $uids = imap_sort(
                $this->Stream,
                $orderCriteria,
                $orderReverse,
                SE_UID,
                join(' ', $searchCriteria)
            );
        }

        // Nothing was found
        if (empty($uids)) {
            return array();
        }

        // Trim resulting ids based on pagination / limitation
        if (@$query['start'] && @$query['end']) {
            $uids = array_slice($uids, @$query['start'], @$query['end'] - @$query['start']);
        } elseif (@$query['limit']) {
            $uids = array_slice($uids, @$query['start'] ? @$query['start'] : 0, @$query['limit']);
        } elseif ($Model->findQueryType === 'first') {
            $uids = array_slice($uids, 0, 1);
        }

        // Format output depending on findQueryType
        if ($Model->findQueryType === 'list') {
            return $uids;
        } else if ($Model->findQueryType === 'count') {
            return array(
                array(
                    $Model->alias => array(
                        'count' => count($uids),
                    ),
                ),
            );
        } else if ($Model->findQueryType === 'all' || $Model->findQueryType === 'first') {
            $attachments = @$query['recursive'] > 0;
            $mails = array();
            foreach ($uids as $uid) {
                if (($mail = $this->_getFormattedMail($Model, $uid, $attachments))) {
                    $mails[] = $mail;
                }
            }
            return $mails;
        }

        return $this->err(
            'Unknown find type %s for query %s',
            $Model->findQueryType,
            $query
        );
    }

    /**
     * no clue
     * @param <type> $Model
     * @param <type> $func
     * @param <type> $params
     * @return <type>
     */
    public function calculate ($Model, $func, $params = array()) {
        $params = (array) $params;
        switch (strtolower($func)) {
            case 'count':
                return 'count';
                break;
        }
    }

    /**
     * connect to the mail server
     */
    public function connect ($Model, $query) {
        if ($this->_isConnected) {
            return true;
        }

        $this->_connectionType = $this->config['type'];

        switch ($this->config['type']) {
            case 'imap':
                $this->_connectionString = sprintf(
                    '{%s:%s%s%s}',
                    $this->config['server'],
                    $this->config['port'],
                    @$this->config['ssl'] ? '/ssl' : '',
                    @$this->config['connect'] ? '/' . @$this->config['connect'] : ''
                );
                break;

            case 'pop3':
                $this->_connectionString = sprintf(
                    '{%s:%s/pop3%s%s}',
                    $this->config['server'],
                    $this->config['port'],
                    @$this->config['ssl'] ? '/ssl' : '',
                    @$this->config['connect'] ? '/' . @$this->config['connect'] : ''
                );
                break;
        }

        try {
            $this->thread = null;
            $retries = 0;
            while (($retries++) < $this->config['retry'] && !$this->thread) {
                $this->Stream = imap_open($this->_connectionString, $this->config['username'], $this->config['password']);
                $this->thread = @imap_thread($this->Stream);
            }

            if (!$this->thread) {
                return $this->err(
                    'Unable to get imap_thread after %s retries. %s',
                    $retries,
                    imap_last_error()
                );
            }
        } catch (Exception $Exception) {
            return $this->err(
                'Unable to get imap_thread after %s retries. %s',
                 $retries,
                $Exception->getMessage() . ' ' . imap_last_error()
            );
        }

        return $this->_isConnected = true;
    }

    public function name ($data) {
        return $data;
    }

    public function sensible ($arguments) {
        if (is_object($arguments)) {
            return get_class($arguments);
        }
        if (!is_array($arguments)) {
            if (!is_numeric($arguments) && !is_bool($arguments)) {
                $arguments = "'".$arguments."'";
            }
            return $arguments;
        }
        $arr = array();
        foreach($arguments as $key=>$val) {
            if (is_array($val)) {
                $val = json_encode($val);
            } elseif (!is_numeric($val) && !is_bool($val)) {
                $val = "'".$val."'";
            }

            if (strlen($val) > 33) {
                $val = substr($val, 0, 30) . '...';
            }
            
            $arr[] = $key.': '.$val;
        }
        return join(', ', $arr);
    }

    public function err ($Model, $format, $arg1 = null, $arg2 = null, $arg3 = null) {
        $arguments = func_get_args();
        $Model     = array_shift($arguments);
        $format    = array_shift($arguments);

        $str = $format;
        if (count($arguments)) {
            foreach($arguments as $k => $v) {
                $arguments[$k] = $this->sensible($v);
            }
            $str = vsprintf($str, $arguments);
        }

        $this->error = $str;
        $Model->onError();
        
        if ($this->config['error_handler'] === 'php') {
            trigger_error($str, E_USER_ERROR);
        }

        return false;
    }
    
    public function lastError () {
        if (($lastError = $this->error)) {
            return $lastError;
        }
        if (($lastError = imap_last_error())) {
            $this->error = $lastError;
            return $lastError;
        }
        return false;
    }

    /**
     * Tries to parse mail & name data from Mail object for to, from, etc.
     * Gracefully degrades where needed
     *
     * Type: to, from, sender, reply_to
     * Need: box, name, host, address, full
     *
     * @param object $Mail
     * @param string $type
     * @param string $need
     *
     * @return mixed string or array
     */
    protected function _personId ($Mail, $type = 'to', $need = null) {
        if ($type === 'sender' && !isset($Mail->sender)) {
            $type = 'from';
        }

        $info['box'] = '';
        if (isset($Mail->{$type}[0]->mailbox)) {
            $info['box'] = $Mail->{$type}[0]->mailbox;
        } 
        $info['name'] = $info['box'];
        if (isset($Mail->{$type}[0]->personal)) {
            $info['name'] = $Mail->{$type}[0]->personal;
        }

        $info['host'] = '';
        if (isset($Mail->{$type}[0]->host)) {
            $info['host'] = $Mail->{$type}[0]->host;
        }
        
        $info['address'] = '';
        if ($info['box'] && $info['host']) {
            $info['address'] = $info['box'] . '@' . $info['host'];
        }

        $info['full'] = $info['address'];
        if ($info['name']) {
            $info['full'] = sprintf('"%s" <%s>', $info['name'], $info['address']);
        }

        if ($need !== null) {
            return $info[$need];
        }
        
        return $info;
    }

    /**
     * get the basic details like sender and reciver with flags like attatchments etc
     *
     * @param int $uid the number of the message
     * @return array empty on error/nothing or array of formatted details
     */
    protected function _getFormattedMail ($Model, $uid, $attachments = false) {
        // Translate uid to msg_no. Has no decent fail
        $msg_number = imap_msgno($this->Stream, $uid);

        // A hack to detect if imap_msgno failed, and we're in fact looking at the wrong mail
        if ($uid != ($mailuid = imap_uid($this->Stream, $msg_number))) {
            pr(compact('Mail'));
            return $this->err(
                $Model,
                'Mail id mismatch. parameter id: %s vs mail id: %s',
                $uid,
                $mailuid
            );
        }

        // Get Mail with a property: 'date' or fail
        if (!($Mail = imap_headerinfo($this->Stream, $msg_number)) || !property_exists($Mail, 'date')) {
            pr(compact('Mail'));
            return $this->err(
                $Model,
                'Unable to find mail date property in Mail corresponding with uid: %s. Something must be wrong',
                $uid
            );
        }

        // Get Mail with a property: 'type' or fail
        if (!($Structure = imap_fetchstructure($this->Stream, $uid, FT_UID)) || !property_exists($Structure, 'type')) {
            pr(compact('Structure'));
            return $this->err(
                $Model,
                'Unable to find structure type property in Mail corresponding with uid: %s. Something must be wrong',
                $uid
            );
        }

        $return[$Model->alias] = array(
            'id' => $this->_toId($uid),
            'message_id' => $Mail->message_id,
            'email_number' => $Mail->Msgno,
            
            'to' => $this->_personId($Mail, 'to', 'address'),
            'to_name' => $this->_personId($Mail, 'to', 'name'),
            'from' => $this->_personId($Mail, 'from', 'address'),
            'from_name' => $this->_personId($Mail, 'from', 'name'),
            'reply_to' => $this->_personId($Mail, 'reply_to', 'address'),
            'reply_to_name' => $this->_personId($Mail, 'reply_to', 'name'),
            'sender' => $this->_personId($Mail, 'sender', 'address'),
            'sender_name' => $this->_personId($Mail, 'sender', 'name'),
            
            'subject' => htmlspecialchars(@$Mail->subject),
            'slug' => Inflector::slug(@$Mail->subject, '-'),
            'header' => @imap_fetchheader($this->Stream, $uid, FT_UID),
            'body' => $this->_getPart($uid, 'TEXT/HTML', $Structure),
            'plainmsg' => $this->_getPart($uid, 'TEXT/PLAIN', $Structure),
            'size' => @$Mail->Size,
            
            'recent' => @$Mail->Recent === 'R' ? 1 : 0,
            'seen' => @$Mail->Unseen === 'U' ? 0 : 1,
            'flagged' => @$Mail->Flagged === 'F' ? 1 : 0,
            'answered' => @$Mail->Answered === 'A' ? 1 : 0,
            'draft' => @$Mail->Draft === 'X' ? 1 : 0,
            'deleted' => @$Mail->Deleted === 'D' ? 1 : 0,

            'thread_count' => $this->_getThreadCount($Mail),
            'attachments' => json_encode($this->_attachment($uid, $Structure)),
            'in_reply_to' => @$Mail->in_reply_to,
            'reference' => @$Mail->references,
            'new' => (int)@$Mail->in_reply_to,
            'created' => date('Y-m-d H:i:s', strtotime($Mail->date)),
        );

        if ($attachments) {
            $return['Attachment'] = $this->_getAttachments($Structure, $uid, $Model);
        }

        // Auto mark after read
        if (!empty($this->config['auto_mark_as'])) {
            $marks = '\\' . join(' \\', $this->config['auto_mark_as']);
            if (!imap_setflag_full($this->Stream, $uid, $marks, ST_UID)) {
                $this->err('Unable to mark email %s as %s', $uid, $marks);
            }
        }

        return $return;
    }

    /**
     * Get any attachments for the current message, images, documents etc
     *
     * @param <type> $Structure
     * @param <type> $uid
     * @return <type>
     */
    protected function _getAttachments ($Structure, $uid, $Model) {
        $attachments = array();
        if (isset($Structure->parts) && count($Structure->parts)) {
            for ($i = 0; $i < count($Structure->parts); $i++) {
                $attachment = array(
                    strtolower(Inflector::singularize($Model->alias) . '_id') => $this->_toId($uid),
                    'message_id' => $uid,
                    'is_attachment' => false,
                    'filename' => '',
                    'mime_type' => '',
                    'type' => '',
                    'name' => '',
                    'size' => 0,
                    'attachment' => ''
                );

                if ($Structure->parts[$i]->ifdparameters) {
                    foreach ($Structure->parts[$i]->dparameters as $object) {
                        if (strtolower($object->attribute) == 'filename') {
                            $attachment['is_attachment'] = true;
                            $attachment['filename'] = $object->value;
                        }
                    }
                }

                if ($Structure->parts[$i]->ifparameters) {
                    foreach ($Structure->parts[$i]->parameters as $object) {
                        if (strtolower($object->attribute) == 'name') {
                            $attachment['is_attachment'] = true;
                            $attachment['name'] = $object->value;
                        }
                    }
                }
                if ($attachment['is_attachment']) {
                    $attachment['attachment'] = imap_fetchbody($this->Stream, $uid, ($i+1), FT_UID | FT_PEEK);
                    if ($Structure->parts[$i]->encoding == 3) {
                        $attachment['format'] = 'base64';
                    } elseif ($Structure->parts[$i]->encoding == 4) {
                        // 4 = QUOTED-PRINTABLE
                        $attachment['attachment'] = quoted_printable_decode($attachment['attachment']);
                        //$attachment['format'] = 'base64';
                    } elseif ($Structure->parts[$i]->encoding == 0) {
                        $attachment['format'] = '7BIT';
                    } elseif ($Structure->parts[$i]->encoding == 1) {
                        $attachment['format'] = '8BIT';
                    } elseif ($Structure->parts[$i]->encoding == 2) {
                        $attachment['format'] = 'BINARY';
                    } elseif ($Structure->parts[$i]->encoding == 5) {
                        $attachment['format'] = 'OTHER';
                    }

                    $attachment['type']      = strtolower($Structure->parts[$i]->subtype);
                    $attachment['mime_type'] = $this->_getMimeType($Structure->parts[$i]);
                    $attachment['size']      = $Structure->parts[$i]->bytes;

                    $attachments[] = $attachment;
                }
            }
        }

        return $attachments;
    }

    /**
     * get id for use in the mail protocol
     *
     * @param <type> $id
     */
    protected function _toUid ($id) {
        if (is_array($id)) {
            return array_map(array($this, __FUNCTION__), $id);
        };

        $uid = $id;
        return $uid;
    }

    /**
     * get id for use in the code
     *
     * @param string $uid in the format <.*@.*> from the email
     *
     * @return mixed on imap its the unique id (int) and for others its a base64_encoded string
     */
    protected function _toId ($uid) {
        if (is_array($uid)) {
            return array_map(array($this, __FUNCTION__), $uid);
        };

        $id = $uid;
        return $id;
    }

    /**
     * used to check / get the attachements in an email.
     *
     * @param object $Structure the structure of the email
     * @param bool $count count them (true), or get them (false)
     *
     * @return mixed, int for check (number of attachements) / array of attachements
     */
    protected function _attachment ($uid, $Structure, $count = true) {
        $has = 0;
        $attachments = array();
        if (isset($Structure->parts)) {
            foreach ($Structure->parts as $partOfPart) {
                if ($count) {
                    $has += $this->_attachment($uid, $partOfPart, $count) == true ? 1 : 0;
                } else {
                    $attachment = $this->_attachment($uid, $partOfPart, $count);
                    if (!empty($attachment)) {
                        $attachments[] = $attachment;
                    }
                }
            }
        } else {
            if (isset($Structure->disposition)) {
                if (strtolower($Structure->disposition) == 'attachment') {
                    if ($count) {
                        return true;
                    } else {
                        return array(
                            'type' => $Structure->type,
                            'subtype' => $Structure->subtype,
                            'file' => $Structure->dparameters[0]->value,
                            'size' => $Structure->bytes
                        );
                    }
                }
            }
        }

        if ($count) {
            return (int)$has;
        }

        return $attachments;
    }

    protected function _getMimeType ($Structure) {
        $primary_mime_type = array('TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'OTHER');
        if ($Structure->subtype) {
            return $primary_mime_type[(int) $Structure->type] . '/' . $Structure->subtype;
        }

        return 'TEXT/PLAIN';
    }

    protected function _getPart ($uid, $mime_type, $Structure = null, $part_number = false) {
        $prefix = null;
        if (!$Structure) {
            return false;
        }

        if ($mime_type == $this->_getMimeType($Structure)) {
            $part_number = $part_number > 0 ? $part_number : 1;
            return imap_fetchbody($this->Stream, $uid, $part_number, FT_UID | FT_PEEK);
        }

        /* multipart */
        if ($Structure->type == 1) {
            foreach ($Structure->parts as $index => $SubStructure) {
                if ($part_number) {
                    $prefix = $part_number . '.';
                }

                $data = $this->_getPart($uid, $mime_type, $SubStructure, $prefix . ($index + 1));
                if ($data) {
                    return quoted_printable_decode($data);
                }
            }
        }
    }

    /**
     * Figure out how many emails there are in the thread for this mail.
     *
     * @param object $Mail the imap header of the mail
     * @return int the number of mails in the thred
     */
    protected function _getThreadCount ($Mail) {
        if (isset($Mail->reference) || isset($Mail->in_reply_to)) {
            return '?';
        }

        return 0;
    }
}