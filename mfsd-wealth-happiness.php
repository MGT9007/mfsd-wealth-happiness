<?php
/**
 * Plugin Name: MFSD Success, Wealth & Happiness Debate
 * Description: AI chatbot debate on success, wealth and happiness with WhatsApp-style interface for 12-14 year olds
 * Version: 1.2.0
 * Author: MisterT9007
 */

if (!defined('ABSPATH')) exit;

final class MFSD_Wealth_Happiness {
    const VERSION = '1.2.0';
    const TBL_CONVERSATIONS = 'mfsd_wealth_and_happiness';
    const SLOT_OPTION = 'mfsd_stevegpt_map_wealth_happiness_chat';

    /** Student-facing personality names (mirrors mfsd-personality-test). Codes are never shown to students. */
    const PERSONALITY_NAMES = array(
        'ISTJ' => array('The Logistician', 'Sentinel'),  'ISFJ' => array('The Defender', 'Sentinel'),
        'ESTJ' => array('The Executive', 'Sentinel'),    'ESFJ' => array('The Consul', 'Sentinel'),
        'INFJ' => array('The Advocate', 'Diplomat'),     'INFP' => array('The Mediator', 'Diplomat'),
        'ENFJ' => array('The Protagonist', 'Diplomat'),  'ENFP' => array('The Campaigner', 'Diplomat'),
        'INTJ' => array('The Architect', 'Analyst'),     'INTP' => array('The Logician', 'Analyst'),
        'ENTJ' => array('The Commander', 'Analyst'),     'ENTP' => array('The Debater', 'Analyst'),
        'ISTP' => array('The Virtuoso', 'Explorer'),     'ISFP' => array('The Adventurer', 'Explorer'),
        'ESTP' => array('The Entrepreneur', 'Explorer'), 'ESFP' => array('The Entertainer', 'Explorer'),
    );

    public static function instance() {
        static $i = null;
        return $i ?: $i = new self();
    }
    
    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'install'));
        add_action('init', array($this, 'assets'));
        add_shortcode('mfsd_wealth_happiness', array($this, 'shortcode'));
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('stevegpt_plugin_integration_slots', array($this, 'register_stevegpt_slots'));
    }

    public function register_stevegpt_slots(array $slots): array {
        $slots[] = array(
            'plugin' => 'Wealth & Happiness',
            'role'   => 'Debate chat',
            'option' => self::SLOT_OPTION,
            'tokens' => array('student_name', 'dream_job', 'personality', 'wellbeing_note', 'conversation', 'message'),
        );
        return $slots;
    }

    public function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . self::TBL_CONVERSATIONS;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE $table (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL,
          conversation_data LONGTEXT NOT NULL,
          last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uniq_user (user_id)
        ) $charset;");
    }

    public function assets() {
        $h = 'mfsd-wealth-happiness';
        $base = plugin_dir_url(__FILE__);

        wp_register_script($h, $base . 'assets/mfsd-wealth-happiness.js', array(), self::VERSION, true);
        wp_register_style($h, $base . 'assets/mfsd-wealth-happiness.css', array(), self::VERSION);
    }

    public function shortcode($atts) {
        wp_localize_script('mfsd-wealth-happiness', 'MFSD_WH_CFG', array(
            'restUrlChat'        => esc_url_raw(rest_url('mfsd-wealth/v1/wealth-chat')),
            'restUrlLoad'        => esc_url_raw(rest_url('mfsd-wealth/v1/wealth-load')),
            'restUrlSave'        => esc_url_raw(rest_url('mfsd-wealth/v1/wealth-save')),
            'restUrlContext'     => esc_url_raw(rest_url('mfsd-wealth/v1/wealth-context')),
            'nonce'              => wp_create_nonce('wp_rest'),
        ));

        wp_enqueue_script('mfsd-wealth-happiness');
        wp_enqueue_style('mfsd-wealth-happiness');

        return '<div id="mfsd-wh-root"></div>';
    }

    public function register_routes() {
        register_rest_route('mfsd-wealth/v1', '/wealth-chat', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'api_chat'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('mfsd-wealth/v1', '/wealth-load', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'api_load'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('mfsd-wealth/v1', '/wealth-save', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'api_save'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('mfsd-wealth/v1', '/wealth-context', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'api_context'),
            'permission_callback' => array($this, 'check_permission'),
        ));
    }

    public function check_permission() {
        return is_user_logged_in();
    }

    private function get_current_user_id() {
        if (function_exists('um_profile_id')) {
            $pid = um_profile_id();
            if ($pid) return (int)$pid;
        }
        return (int)get_current_user_id();
    }

    public function api_context() {
        $user_id = $this->get_current_user_id();

        if (!$user_id) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Not logged in'), 401);
        }

        return new WP_REST_Response(array(
            'ok' => true,
            'context' => $this->build_context($user_id)
        ), 200);
    }

    /**
     * Student personalisation, built server-side (never trusted from the client).
     */
    private function build_context($user_id) {
        global $wpdb;
        $context = array();

        // Dream job
        $dream_jobs_table = $wpdb->prefix . 'mfsd_ai_dream_jobs_results';
        if ($wpdb->get_var("SHOW TABLES LIKE '$dream_jobs_table'") == $dream_jobs_table) {
            $dream_job = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $dream_jobs_table WHERE user_id=%d ORDER BY created_at DESC LIMIT 1",
                $user_id
            ), ARRAY_A);
            if ($dream_job) {
                $context['dream_job'] = $dream_job;
            }
        }

        // RAG answers (wellbeing signal)
        $rag_table = $wpdb->prefix . 'mfsd_rag_answers';
        if ($wpdb->get_var("SHOW TABLES LIKE '$rag_table'") == $rag_table) {
            $rag_summary = $wpdb->get_results($wpdb->prepare(
                "SELECT week_num, answer, COUNT(*) as cnt FROM $rag_table WHERE user_id=%d GROUP BY week_num, answer ORDER BY week_num DESC",
                $user_id
            ), ARRAY_A);
            if ($rag_summary) {
                $context['rag_summary'] = $rag_summary;
            }
        }

        // Personality — prefer the Who Am I result, fall back to the Weekly RAG result.
        // Only the student-facing name is exposed; the 4-letter code stays server-side.
        $type_code   = '';
        $ptest_table = $wpdb->prefix . 'mfsd_ptest_results';
        if ($wpdb->get_var("SHOW TABLES LIKE '$ptest_table'") == $ptest_table) {
            $type_code = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT mbti_type FROM $ptest_table WHERE user_id=%d AND mbti_type IS NOT NULL AND mbti_type <> '' ORDER BY updated_at DESC LIMIT 1",
                $user_id
            ));
        }
        $mbti_results_table = $wpdb->prefix . 'mfsd_mbti_results';
        if (!$type_code && $wpdb->get_var("SHOW TABLES LIKE '$mbti_results_table'") == $mbti_results_table) {
            $type_code = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT type4 FROM $mbti_results_table WHERE user_id=%d ORDER BY week_num DESC LIMIT 1",
                $user_id
            ));
        }
        $type_code = strtoupper(trim($type_code));
        if (isset(self::PERSONALITY_NAMES[$type_code])) {
            $context['personality'] = array(
                'name'   => self::PERSONALITY_NAMES[$type_code][0],
                'family' => self::PERSONALITY_NAMES[$type_code][1],
            );
        }

        // Display name
        if (function_exists('um_get_display_name')) {
            $username = um_get_display_name($user_id);
        } else {
            $user = get_userdata($user_id);
            $username = $user ? $user->display_name : '';
        }
        $context['username'] = $username;

        return $context;
    }

    public function api_load() {
        global $wpdb;
        $user_id = $this->get_current_user_id();
        
        if (!$user_id) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Not logged in'), 401);
        }

        $table = $wpdb->prefix . self::TBL_CONVERSATIONS;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT conversation_data FROM $table WHERE user_id=%d",
            $user_id
        ), ARRAY_A);

        if ($row && isset($row['conversation_data'])) {
            $messages = json_decode($row['conversation_data'], true);
            return new WP_REST_Response(array(
                'ok' => true,
                'messages' => is_array($messages) ? $messages : array()
            ), 200);
        }

        return new WP_REST_Response(array(
            'ok' => true,
            'messages' => array()
        ), 200);
    }

    public function api_save($request) {
        global $wpdb;
        $user_id = $this->get_current_user_id();
        
        if (!$user_id) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Not logged in'), 401);
        }

        $params = $request->get_json_params();
        $messages = isset($params['messages']) ? $params['messages'] : array();

        if (!is_array($messages)) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid messages'), 400);
        }

        $table = $wpdb->prefix . self::TBL_CONVERSATIONS;
        $conversation_json = json_encode($messages);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id=%d",
            $user_id
        ));

        if ($existing) {
            $wpdb->update(
                $table,
                array('conversation_data' => $conversation_json),
                array('user_id' => $user_id),
                array('%s'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'user_id' => $user_id,
                    'conversation_data' => $conversation_json
                ),
                array('%d', '%s')
            );
        }

        return new WP_REST_Response(array('ok' => true), 200);
    }

    public function api_chat($request) {
        $user_id = $this->get_current_user_id();

        if (!$user_id) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Not logged in'), 401);
        }

        $params = $request->get_json_params();
        $user_message = isset($params['message']) ? trim((string) $params['message']) : '';
        $conversation_history = (isset($params['conversation']) && is_array($params['conversation'])) ? $params['conversation'] : array();

        if (empty($user_message)) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'No message'), 400);
        }

        $chatbot_id = get_option(self::SLOT_OPTION, '');
        if (!$chatbot_id || !class_exists('SteveGPT_Chatbot')) {
            error_log('[MFSD Wealth Happiness] SteveGPT chatbot not configured (' . self::SLOT_OPTION . ') or SteveGPT inactive.');
            return new WP_REST_Response(array('ok' => false, 'error' => 'AI not available'), 500);
        }

        try {
            // Context is always rebuilt server-side — any client-sent context is ignored
            $user_context = $this->build_context($user_id);

            // Last 6 messages keep the context manageable
            $history_text = '';
            foreach (array_slice($conversation_history, -6) as $msg) {
                if (!is_array($msg) || !isset($msg['text'])) continue;
                $role = (isset($msg['sender']) && $msg['sender'] === 'user') ? 'User' : 'AI';
                $history_text .= $role . ': ' . sanitize_textarea_field((string) $msg['text']) . "\n";
            }

            $chatbot = SteveGPT_Chatbot::get($chatbot_id);
            $config  = $chatbot->get_config();

            if (!empty($config['prompt_template'])) {
                $personality = isset($user_context['personality'])
                    ? $user_context['personality']['name'] . ' (' . $user_context['personality']['family'] . ' family)'
                    : '';
                $prompt = $chatbot->render_prompt(array(
                    'student_name'   => $user_context['username'] ?: 'there',
                    'dream_job'      => $user_context['dream_job']['job_title'] ?? '',
                    'personality'    => $personality,
                    'wellbeing_note' => $this->wellbeing_note($user_context),
                    'conversation'   => $history_text,
                    'message'        => $user_message,
                ));
            } else {
                // No template on the chatbot — send the built-in debate prompt
                $prompt = $this->build_system_prompt($user_context) . "\n\n" . $history_text . "User: $user_message\nAI:";
            }

            $ai_response = $chatbot->query($prompt, $user_id);

            if (empty($ai_response)) {
                throw new Exception('Empty AI response');
            }

            return new WP_REST_Response(array(
                'ok' => true,
                'response' => $ai_response
            ), 200);

        } catch (Exception $e) {
            error_log('[MFSD Wealth Happiness] AI error: ' . $e->getMessage());
            return new WP_REST_Response(array(
                'ok' => false,
                'error' => 'AI processing failed'
            ), 500);
        }
    }

    private function wellbeing_note($context) {
        if (empty($context['rag_summary'])) return '';
        $reds = 0;
        $greens = 0;
        foreach ($context['rag_summary'] as $row) {
            if ($row['answer'] === 'R') $reds += (int) $row['cnt'];
            if ($row['answer'] === 'G') $greens += (int) $row['cnt'];
        }
        if ($reds > $greens * 1.5) {
            return "Note: Their wellbeing assessments suggest they may be experiencing some challenges. ";
        }
        if ($greens > $reds * 1.5) {
            return "Note: Their wellbeing assessments indicate they're generally doing well. ";
        }
        return '';
    }

    private function build_system_prompt($context) {
        $dream_job   = isset($context['dream_job']['job_title']) ? $context['dream_job']['job_title'] : null;
        $personality = isset($context['personality']) ? $context['personality'] : null;

        $context_info = '';
        if ($dream_job) {
            $context_info .= "The student's dream job is: $dream_job. ";
        }
        if ($personality) {
            $context_info .= "Their Who Am I personality type is {$personality['name']} ({$personality['family']} family). ";
        }

        $wellbeing_note = $this->wellbeing_note($context);

        $prompt = <<<PROMPT
You are a thoughtful, engaging debate facilitator for the "Success, Wealth & Happiness Part 2" discussion. You're having a friendly but intellectually challenging WhatsApp-style conversation with a 12-14 year old student about whether successful, wealthy people are truly happy.

CORE PHILOSOPHY - SOLUTIONS MINDSET:
- Help young people develop critical thinking about success and happiness
- Challenge assumptions respectfully and encourage deeper reflection
- Use age-appropriate language (12-14 year olds) - be friendly, not condescending
- Stay curious and Socratic - ask thought-provoking questions
- Gently oppose or challenge whatever view they present to help them think more deeply
- Present alternative perspectives they might not have considered
- Keep responses conversational and relatively brief (2-4 sentences typically)
- Use examples they can relate to (young influencers, athletes, musicians, etc.)

DEBATE APPROACH:
- If they say wealth = happiness, challenge them to think about wealthy people who struggle
- If they say wealth ≠ happiness, challenge them about how poverty creates stress
- Play devil's advocate in a supportive, educational way
- Ask them to consider specific examples and edge cases
- Encourage nuance over black-and-white thinking

STUDENT CONTEXT:
$context_info$wellbeing_note

YOUR TONE:
- Warm and supportive, never preachy
- Genuinely curious about their thinking
- Respectful of their views while encouraging deeper analysis
- Use everyday language, occasional light emoji (👀 🤔 etc.) to keep it conversational
- Like a cool older sibling or mentor, not a teacher

IMPORTANT:
- Keep responses short and punchy (WhatsApp style)
- Ask open-ended follow-up questions
- Use their context (dream job, personality type) when relevant
- Never mention MBTI, Myers-Briggs, DISC or 4-letter personality codes
- Never lecture - have a conversation
- Let them explore ideas rather than giving them "the answer"

Remember: The goal is to help them think critically about the relationship between success, wealth, and happiness - not to convince them of any particular viewpoint.
PROMPT;

        return $prompt;
    }
}

MFSD_Wealth_Happiness::instance();