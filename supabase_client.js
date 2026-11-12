// Server World - Supabase Cloud Synchronization Layer
const SUPABASE_URL = 'https://twzjornmyouhwiyhqdol.supabase.co';
const SUPABASE_ANON_KEY = 'sb_publishable_ws4prTv9Gics_fZ1N5TxJw_RkaN3sEU';

let supabaseClient = null;
try {
  if (window.supabase && window.supabase.createClient) {
    supabaseClient = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);
  }
} catch (e) {
  console.warn('Supabase init error:', e);
}

// ==================== ADMIN SETTINGS / PASSWORD ====================
async function dbGetAdminPassword() {
  try {
    if (!supabaseClient) return localStorage.getItem('sw_admin_pass') || 'admin123';
    const { data, error } = await supabaseClient
      .from('admin_settings')
      .select('value')
      .eq('key', 'admin_password')
      .single();
    if (data && data.value) {
      localStorage.setItem('sw_admin_pass', data.value);
      return data.value;
    }
  } catch (e) {
    console.warn('Error fetching admin password:', e);
  }
  return localStorage.getItem('sw_admin_pass') || 'admin123';
}

async function dbSetAdminPassword(newPassword) {
  localStorage.setItem('sw_admin_pass', newPassword);
  try {
    if (supabaseClient) {
      await supabaseClient
        .from('admin_settings')
        .upsert({ key: 'admin_password', value: newPassword, updated_at: new Date().toISOString() });
    }
  } catch (e) {
    console.warn('Error saving admin password:', e);
  }
}

// ==================== COURSES ====================
async function dbGetCourses() {
  try {
    if (supabaseClient) {
      const { data, error } = await supabaseClient
        .from('courses')
        .select('*')
        .order('created_at', { ascending: true });
      if (data && data.length > 0) {
        localStorage.setItem('sw_courses', JSON.stringify(data));
        return data;
      }
    }
  } catch (e) {
    console.warn('Error fetching courses from cloud:', e);
  }
  return JSON.parse(localStorage.getItem('sw_courses') || '[]');
}

async function dbSaveCourse(course) {
  try {
    if (supabaseClient) {
      await supabaseClient.from('courses').upsert(course);
    }
  } catch (e) {
    console.warn('Error saving course to cloud:', e);
  }
}

async function dbDeleteCourse(id) {
  try {
    if (supabaseClient) {
      await supabaseClient.from('courses').delete().eq('id', id);
    }
  } catch (e) {
    console.warn('Error deleting course from cloud:', e);
  }
}

// ==================== EXAMS ====================
async function dbGetExams() {
  try {
    if (supabaseClient) {
      const { data, error } = await supabaseClient
        .from('exams')
        .select('*')
        .order('id', { ascending: false });
      if (data && data.length > 0) {
        // Map database column names to local camelCase if needed
        const mapped = data.map(e => ({
          ...e,
          testType: e.test_type || e.testType || 'live',
          accessCode: e.access_code || e.accessCode || '',
          maxMarks: e.max_marks || e.maxMarks || 100,
          result_visibility: e.result_visibility || 'immediate',
          certificateThreshold: e.certificate_threshold || e.certificateThreshold || 85,
          paperType: e.paper_type || e.paperType || 'interactive',
          questions: e.questions || []
        }));
        localStorage.setItem('sw_exams', JSON.stringify(mapped));
        return mapped;
      }
    }
  } catch (e) {
    console.warn('Error fetching exams from cloud:', e);
  }
  return JSON.parse(localStorage.getItem('sw_exams') || '[]');
}

async function dbSaveExam(exam) {
  try {
    if (supabaseClient) {
      const dbRow = {
        id: exam.id,
        title: exam.title,
        category: exam.category,
        test_type: exam.testType || 'live',
        access_code: exam.accessCode || '',
        duration: exam.duration || 15,
        max_marks: exam.maxMarks || 100,
        result_visibility: exam.result_visibility || 'immediate',
        certificate_threshold: exam.certificateThreshold || 85,
        single_attempt: exam.single_attempt !== false,
        paper_type: exam.paperType || 'interactive',
        status: exam.status || 'LIVE',
        questions: exam.questions || []
      };
      await supabaseClient.from('exams').upsert(dbRow);
    }
  } catch (e) {
    console.warn('Error saving exam to cloud:', e);
  }
}

async function dbDeleteExam(id) {
  try {
    if (supabaseClient) {
      await supabaseClient.from('exams').delete().eq('id', id);
    }
  } catch (e) {
    console.warn('Error deleting exam from cloud:', e);
  }
}

// ==================== CANDIDATE PROFILES ====================
async function dbGetProfiles() {
  try {
    if (supabaseClient) {
      const { data, error } = await supabaseClient
        .from('profiles')
        .select('*')
        .order('created_at', { ascending: false });
      if (data && data.length > 0) {
        const mapped = data.map(p => ({
          ...p,
          assigned_exam_title: p.assigned_exam_title || 'All Assessments',
          assigned_courses: p.assigned_courses || ['All Assessments']
        }));
        localStorage.setItem('sw_profiles', JSON.stringify(mapped));
        return mapped;
      }
    }
  } catch (e) {
    console.warn('Error fetching profiles from cloud:', e);
  }
  return JSON.parse(localStorage.getItem('sw_profiles') || '[]');
}

async function dbSaveProfile(p) {
  try {
    if (supabaseClient) {
      const dbRow = {
        id: p.id || ('cand_' + Date.now()),
        name: p.name,
        roll: p.roll || p.username,
        username: p.username || p.roll,
        password: p.password,
        assigned_exam_title: p.assigned_exam_title || 'All Assessments',
        assigned_courses: p.assigned_courses || ['All Assessments'],
        photo: p.photo || '',
        created: p.created || new Date().toISOString().replace('T', ' ').substr(0, 19)
      };
      await supabaseClient.from('profiles').upsert(dbRow, { onConflict: 'roll' });
    }
  } catch (e) {
    console.warn('Error saving profile to cloud:', e);
  }
}

async function dbDeleteProfile(roll) {
  try {
    if (supabaseClient) {
      await supabaseClient.from('profiles').delete().eq('roll', roll);
    }
  } catch (e) {
    console.warn('Error deleting profile from cloud:', e);
  }
}

// ==================== EXAM RESULTS ====================
async function dbGetResults() {
  try {
    if (supabaseClient) {
      const { data, error } = await supabaseClient
        .from('results')
        .select('*')
        .order('created_at', { ascending: false });
      if (data && data.length > 0) {
        const mapped = data.map(r => ({
          ...r,
          attemptId: r.attempt_id || r.id,
          examTitle: r.exam_title,
          examId: r.exam_id,
          accessCode: r.access_code,
          totalMarks: r.total_marks || r.total || 10,
          timeTaken: r.time_taken,
          tabSwitchCount: r.tab_switch_count || 0,
          fullscreenExitCount: r.fullscreen_exit_count || 0,
          solutions: r.solutions || []
        }));
        localStorage.setItem('sw_results', JSON.stringify(mapped));
        return mapped;
      }
    }
  } catch (e) {
    console.warn('Error fetching results from cloud:', e);
  }
  return JSON.parse(localStorage.getItem('sw_results') || '[]');
}

async function dbSaveResult(r) {
  try {
    if (supabaseClient) {
      const dbRow = {
        id: r.attemptId || ('res_' + Date.now()),
        attempt_id: r.attemptId || ('res_' + Date.now()),
        name: r.name || 'Candidate',
        roll: r.roll || '-',
        exam_title: r.examTitle || 'Online Exam',
        exam_id: r.examId ? parseInt(r.examId) : null,
        access_code: r.accessCode || '',
        score: parseFloat(r.score || 0),
        total: parseFloat(r.total || 0),
        total_marks: parseFloat(r.totalMarks || r.total || 10),
        accuracy: parseFloat(r.accuracy || 0),
        time_taken: r.timeTaken || '',
        tab_switch_count: parseInt(r.tabSwitchCount || 0),
        fullscreen_exit_count: parseInt(r.fullscreenExitCount || 0),
        solutions: r.solutions || [],
        photo: r.photo || '',
        timestamp: r.timestamp || new Date().toISOString().replace('T', ' ').substr(0, 19)
      };
      await supabaseClient.from('results').upsert(dbRow);
    }
  } catch (e) {
    console.warn('Error saving result to cloud:', e);
  }
}

async function dbDeleteResult(attemptId) {
  try {
    if (supabaseClient) {
      await supabaseClient.from('results').delete().eq('attempt_id', attemptId);
    }
  } catch (e) {
    console.warn('Error deleting result from cloud:', e);
  }
}
