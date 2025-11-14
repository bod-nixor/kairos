-- Suggested indexes to support RBAC course/room/queue lookups.
-- Apply via your migration toolchain if these indexes are missing.

ALTER TABLE rooms
    ADD INDEX idx_rooms_course_id (course_id);

ALTER TABLE queues
    ADD INDEX idx_queues_room_id (room_id);

ALTER TABLE queue_entries
    ADD INDEX idx_queue_entries_queue_user (queue_id, user_id);
