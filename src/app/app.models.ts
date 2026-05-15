export interface Activity {
  id: number;
  title: string;
  startTime: string;
  endTime: string;
  assignee: string;
  visibility: 'private' | 'public';
  completed: boolean;
  location: string;
  description: string;
  date: string;
  reminderMinutes: number | null;
}

export interface GeneralPending {
  id: number;
  title: string;
  assignee: string;
  description: string;
  date: string;
}

export interface FinancialEntry {
  id: number;
  title: string;
  type: 'income' | 'expense';
  amount: number;
  assignee: string;
  description: string;
  date: string;
}

export interface CalendarDay {
  date: Date;
  isoDate: string;
  dayNumber: number;
  inCurrentMonth: boolean;
  isToday: boolean;
  activities: Activity[];
}

export interface AuthUser {
  id: number;
  name: string;
  username: string;
  profilePublic: boolean;
  publicUrl: string;
  whatsappNumber: string;
  whatsappNotificationsEnabled: boolean;
}

export interface AuthSession {
  authenticated: boolean;
  user: AuthUser | null;
  canRegister: boolean;
}

export interface PublicProfile {
  found: boolean;
  profileEnabled: boolean;
  user: Pick<AuthUser, 'name' | 'username' | 'publicUrl'> | null;
  activities: Activity[];
}

export interface WeekGroup {
  label: string;
  activities: Activity[];
}
