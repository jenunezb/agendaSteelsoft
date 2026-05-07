export interface Activity {
  id: number;
  title: string;
  startTime: string;
  endTime: string;
  assignee: string;
  completed: boolean;
  location: string;
  description: string;
  date: string;
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

export interface WeekGroup {
  label: string;
  activities: Activity[];
}
