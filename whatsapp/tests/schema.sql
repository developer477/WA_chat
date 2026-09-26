-- Minimal contract fixture, NOT a production VICIdial schema or migration.
-- Native tables intentionally use MyISAM to exercise mixed-engine recovery.
CREATE TABLE system_settings (allow_chats INT NOT NULL);
INSERT INTO system_settings VALUES (1);
CREATE TABLE vicidial_inbound_dids (
 did_id INT PRIMARY KEY, did_pattern VARCHAR(50), did_active VARCHAR(1), custom_one VARCHAR(100), custom_two VARCHAR(100)
) ENGINE=MyISAM;
CREATE TABLE vicidial_inbound_groups (
 group_id VARCHAR(20) PRIMARY KEY, active VARCHAR(1), group_handling VARCHAR(10),
 hold_time_option_callback_list_id INT, custom_one TEXT, custom_two TEXT, custom_three TEXT
) ENGINE=MyISAM;
CREATE TABLE vicidial_list (
 lead_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, phone_number VARCHAR(18),status VARCHAR(6),entry_date DATETIME,
 first_name VARCHAR(30),last_name VARCHAR(30),email VARCHAR(70),list_id INT,security_phrase VARCHAR(100)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
CREATE TABLE vicidial_live_chats (
 chat_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, status ENUM('WAITING','LIVE','DEAD','DROP') DEFAULT 'WAITING',
 chat_creator VARCHAR(20),group_id VARCHAR(20),lead_id INT,chat_start_time DATETIME,
 transferring_agent VARCHAR(20),user_direct VARCHAR(20),user_direct_group_id VARCHAR(20)
) ENGINE=MyISAM;
CREATE TABLE vicidial_chat_archive LIKE vicidial_live_chats;
CREATE TABLE vicidial_chat_participants (
 chat_id INT,chat_member VARCHAR(20),chat_member_name VARCHAR(50),vd_agent VARCHAR(1),ping_date DATETIME,
 typing_status VARCHAR(20),user_status VARCHAR(20),PRIMARY KEY(chat_id,chat_member)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
CREATE TABLE vicidial_chat_log (
 message_row_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,chat_id INT,message TEXT,message_time DATETIME,
 poster VARCHAR(20),chat_member_name VARCHAR(50),chat_level INT,KEY chat(chat_id)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
CREATE TABLE vicidial_chat_log_archive LIKE vicidial_chat_log;
CREATE TABLE vicidial_users (user VARCHAR(20) PRIMARY KEY) ENGINE=MyISAM;
CREATE TABLE chat_id_lead (chat_id INT PRIMARY KEY,status VARCHAR(20)) ENGINE=MyISAM;
INSERT INTO vicidial_users VALUES ('agent1'),('agent2');
INSERT INTO vicidial_inbound_groups VALUES ('TSIM','Y','CHAT',999,REPEAT('x',202),REPEAT('a',32),'test-verification');
INSERT INTO vicidial_inbound_dids VALUES (12,'917045963025','Y','TSIM','773505685855835'),(13,'919999999999','Y','TSIM','773505685855836');
